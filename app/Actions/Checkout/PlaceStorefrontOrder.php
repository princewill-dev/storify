<?php

namespace App\Actions\Checkout;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vat;
use App\Services\StockLedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlaceStorefrontOrder
{
    public function __construct(private readonly StockLedgerService $stockLedger) {}

    public function execute(
        Store $store,
        ?Customer $customer,
        array $data,
        ?string $guestToken,
        ?string $ipAddress,
    ): Order {
        return DB::transaction(function () use ($store, $customer, $data, $guestToken, $ipAddress): Order {
            $cart = $this->lockCart($store, $customer, $data['checkout_token'] ?? null, $guestToken);

            if (! $cart) {
                throw new DomainException('Your cart is empty or this checkout session has already been completed.');
            }

            $cart->load('items');
            if ($cart->items->isEmpty()) {
                throw new DomainException('Your cart is empty.');
            }

            $customer ??= Customer::firstOrCreate(
                ['business_id' => $store->business_id, 'email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone' => $data['phone'],
                    'business_id' => $store->business_id,
                    'ip_address' => $ipAddress,
                    'password' => bcrypt(Str::random(32)),
                    'street_address' => $data['street_address'],
                    'city' => $data['city'],
                    'state' => $data['state'],
                    'country' => $data['country'] ?? 'Nigeria',
                ]
            );

            if ((int) $customer->business_id !== (int) $store->business_id) {
                throw new DomainException('This customer account belongs to another business.');
            }

            if ($cart->user_id && (
                (int) $cart->user_id !== (int) $customer->id
                || ($cart->user_type && $cart->user_type !== Customer::class)
            )) {
                throw new DomainException('Invalid checkout session.');
            }

            if (! $cart->user_id) {
                $cart->update(['user_id' => $customer->id, 'user_type' => Customer::class]);
            }

            $productIds = $cart->items->pluck('product_id')->filter()->unique();
            $products = Product::query()
                ->where('store_id', $store->id)
                ->where('business_id', $store->business_id)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw new DomainException('One or more cart items are no longer available.');
            }

            $stockLocations = StockLocation::query()
                ->where('locationable_type', Store::class)
                ->where('locationable_id', $store->id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $subtotalKobo = 0;
            $taxKobo = 0;
            $orderItems = [];

            $vatPercentage = (float) (Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first()?->percentage ?? 0);

            foreach ($cart->items as $cartItem) {
                $product = $products->get($cartItem->product_id);
                $quantity = (int) $cartItem->qty;
                $stockLocation = $stockLocations->get($product->id);
                $available = $stockLocation ? (int) $stockLocation->quantity : (int) $product->quantity;

                if ($available < $quantity || (int) $product->quantity < $quantity) {
                    throw new DomainException("{$product->name}: only {$available} available (requested {$quantity}).");
                }

                $unitAmountKobo = (int) ($cartItem->unit_amount ?: round((float) $product->amount * 100));
                $lineSubtotalKobo = (int) ($cartItem->line_subtotal ?: $unitAmountKobo * $quantity);
                $subtotalKobo += $lineSubtotalKobo;

                $lineTaxKobo = 0;
                if ($vatPercentage > 0 && $product->is_taxable) {
                    $lineTaxKobo = (int) round($lineSubtotalKobo * $vatPercentage / 100);
                    $taxKobo += $lineTaxKobo;
                }

                $costKobo = app(\App\Services\Accounting\InventoryCostingService::class)
                    ->costForSale($product, $quantity);

                $orderItems[] = [
                    'product' => $product,
                    'stock_location' => $stockLocation,
                    'quantity' => $quantity,
                    'attributes' => [
                        'product_id' => $product->id,
                        'product_name' => $cartItem->name ?: $product->name,
                        'product_code' => $product->product_code,
                        'unit_price' => round($unitAmountKobo / 100, 2),
                        'quantity' => $quantity,
                        'subtotal' => round($lineSubtotalKobo / 100, 2),
                        'tax_rate' => $product->is_taxable ? $vatPercentage : 0,
                        'tax_amount' => round($lineTaxKobo / 100, 2),
                        'cost_kobo' => $costKobo > 0 ? $costKobo : null,
                    ],
                ];
            }

            $routeId = $cart->delivery_route_id ?: ($data['delivery_route_id'] ?? null);
            $deliveryRoute = $routeId
                ? DeliveryRoute::query()
                    ->where('store_id', $store->id)
                    ->where('active', true)
                    ->find($routeId)
                : null;
            $shippingFee = (float) ($deliveryRoute?->fee ?? 0) / 100;
            $subtotal = round($subtotalKobo / 100, 2);
            $tax = round($taxKobo / 100, 2);

            $deliveryAddress = $customer->deliveryAddresses()->create([
                'recipient_name' => $customer->full_name,
                'recipient_phone' => $customer->phone,
                'street_address' => $data['street_address'],
                'apartment' => $data['apartment'] ?? null,
                'country' => $data['country'] ?? 'Nigeria',
                'state' => $data['state'],
                'city' => $data['city'],
                'landmark' => $data['landmark'] ?? null,
                'delivery_route_id' => $deliveryRoute?->id,
            ]);

            $ownerId = $store->user_id && User::query()->whereKey($store->user_id)->exists()
                ? $store->user_id
                : null;

            $order = Order::create([
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'user_id' => $ownerId,
                'customer_id' => $customer->id,
                'source' => data_get($cart->meta, 'source', 'checkout'),
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'total' => round($subtotal + $shippingFee + $tax, 2),
                'status' => OrderStatus::PENDING->value,
                'delivery_state' => $data['state'],
                'delivery_area' => $data['city'],
                'delivery_route_id' => $deliveryRoute?->id,
                'delivery_days' => $deliveryRoute?->delivery_days,
                'notes' => $data['notes'] ?? null,
                'delivery_address_id' => $deliveryAddress->id,
            ]);

            foreach ($orderItems as $item) {
                OrderItem::create(['order_id' => $order->id, ...$item['attributes']]);

                $stockLocation = $item['stock_location'] ?? StockLocation::create([
                    'product_id' => $item['product']->id,
                    'locationable_type' => Store::class,
                    'locationable_id' => $store->id,
                    'business_id' => $store->business_id,
                    'quantity' => (int) $item['product']->quantity,
                ]);

                Product::query()->whereKey($item['product']->id)->decrement('quantity', $item['quantity']);
                $this->stockLedger->recordRemoval(
                    $stockLocation,
                    $item['quantity'],
                    $order,
                    null,
                    'Storefront order — #'.$order->order_number
                );
            }

            $cart->items()->delete();
            $cart->update(['status' => 'completed']);

            $ledger = app(\App\Services\Accounting\LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postOrderRevenue($order));

            return $order;
        });
    }

    private function lockCart(Store $store, ?Customer $customer, ?string $checkoutToken, ?string $guestToken): ?Cart
    {
        $base = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($checkoutToken) {
            return (clone $base)->where('checkout_token', $checkoutToken)->lockForUpdate()->first();
        }

        if ($customer) {
            $cart = (clone $base)->where('user_id', $customer->id)->lockForUpdate()->first();
            if ($cart) {
                return $cart;
            }
        }

        return $guestToken
            ? (clone $base)->where('guest_token', $guestToken)->lockForUpdate()->first()
            : null;
    }
}
