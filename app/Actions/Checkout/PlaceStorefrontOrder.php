<?php

namespace App\Actions\Checkout;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vat;
use App\Services\Accounting\InventoryCostingService;
use App\Services\Accounting\LedgerPostingService;
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

            if (! $customer) {
                // The storefront that places account-less orders is still the
                // deployed one, so while the flag is off a customer is
                // fabricated here exactly as it always was: matched on
                // (business_id, email) so a returning shopper keeps one row
                // and its history. `registered_at` is deliberately not set —
                // this row has no password its owner chose, which is what
                // makes it claimable rather than an account.
                //
                // Both this branch and the flag go together, once no deployed
                // bundle can reach it.
                if (config('storefront.checkout_requires_account')) {
                    throw new DomainException('An account is required to place this order.');
                }

                $customer = Customer::firstOrCreate(
                    ['business_id' => $store->business_id, 'email' => $data['email']],
                    [
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'phone' => $data['phone'],
                        'business_id' => $store->business_id,
                        'ip_address' => $ipAddress,
                        'password' => bcrypt(Str::random(32)),
                        'street_address' => $data['street_address'] ?? null,
                        'city' => $data['city'] ?? null,
                        'state' => $data['state'] ?? null,
                        'country' => $data['country'] ?? 'Nigeria',
                    ]
                );
            }

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
                $isDigital = (bool) $product->is_digital;
                $stockLocation = $stockLocations->get($product->id);
                $available = $stockLocation ? (int) $stockLocation->quantity : (int) $product->quantity;

                // Digital products have unlimited stock
                if (! $isDigital && ($available < $quantity || (int) $product->quantity < $quantity)) {
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

                $costKobo = $isDigital
                    ? 0
                    : app(InventoryCostingService::class)->costForSale($product, $quantity);

                $orderItems[] = [
                    'product' => $product,
                    'stock_location' => $stockLocation,
                    'quantity' => $quantity,
                    'is_digital' => $isDigital,
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
                        'is_digital' => $isDigital,
                    ],
                ];
            }

            $containsDigital = collect($orderItems)->contains(fn ($item) => $item['is_digital']);
            $requiresShipping = collect($orderItems)->contains(fn ($item) => ! $item['is_digital']);

            $routeId = $requiresShipping ? ($cart->delivery_route_id ?: ($data['delivery_route_id'] ?? null)) : null;
            $deliveryRoute = $routeId
                ? DeliveryRoute::query()
                    ->where('store_id', $store->id)
                    ->where('active', true)
                    ->find($routeId)
                : null;
            $shippingFee = $requiresShipping ? (float) ($deliveryRoute?->fee ?? 0) / 100 : 0.0;
            $subtotal = round($subtotalKobo / 100, 2);
            $tax = round($taxKobo / 100, 2);

            $deliveryAddress = $requiresShipping
                ? $this->recordDeliveryAddress($customer, $data, $deliveryRoute)
                : null;

            $ownerId = $store->user_id && User::query()->whereKey($store->user_id)->exists()
                ? $store->user_id
                : null;

            $order = Order::create([
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'user_id' => $ownerId,
                'customer_id' => $customer->id,
                'source' => $containsDigital ? 'digital' : data_get($cart->meta, 'source', 'checkout'),
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'total' => round($subtotal + $shippingFee + $tax, 2),
                'status' => OrderStatus::PENDING->value,
                'delivery_state' => $requiresShipping ? ($data['state'] ?? null) : null,
                'delivery_area' => $requiresShipping ? ($data['city'] ?? null) : null,
                'delivery_route_id' => $deliveryRoute?->id,
                'delivery_days' => $deliveryRoute?->delivery_days,
                'notes' => $data['notes'] ?? null,
                'delivery_address_id' => $deliveryAddress?->id,
                'meta' => array_filter([
                    'contains_digital' => $containsDigital ?: null,
                ]),
            ]);

            foreach ($orderItems as $item) {
                OrderItem::create(['order_id' => $order->id, ...$item['attributes']]);

                // Digital products have no stock to move
                if ($item['is_digital']) {
                    continue;
                }

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

            $ledger = app(LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postOrderRevenue($order));

            return $order;
        });
    }

    /**
     * The address this order will be delivered to, as a row of its own.
     *
     * An order's address is a snapshot, not a reference. The customer may edit
     * or delete a saved address the day after ordering — or move house — and
     * the fulfilment record must still say where the parcel actually went, so
     * every order gets its own row (`is_snapshot`) copied from whichever source
     * was chosen. That is what the table always did; the book is what made the
     * distinction need a name.
     *
     * `delivery_address_id` selects a saved address, which is then used
     * verbatim — the flat fields are the fallback, not an override, so a client
     * that sends both gets what it asked for by id and no silent half-merge.
     * The id is re-checked against the customer here rather than trusted from
     * the payload: PlaceOrderRequest's `exists` rule refuses a foreign id too,
     * but the row is the one thing that decides where goods go.
     *
     * A plain typed-in address is also saved to the customer's book, so the
     * next order can be placed without retyping it. Only for a real account:
     * a row checkout fabricated in an older release has no one to show a book
     * to, and a customer with no address yet makes their first entry default —
     * the same rule AddressController::store applies, for the same reason.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordDeliveryAddress(Customer $customer, array $data, ?DeliveryRoute $deliveryRoute): DeliveryAddress
    {
        $saved = null;

        if (! empty($data['delivery_address_id'])) {
            $saved = $customer->deliveryAddresses()
                ->where('is_snapshot', false)
                ->whereKey($data['delivery_address_id'])
                ->first();

            if (! $saved) {
                throw new DomainException('That delivery address is not one of yours.');
            }
        }

        $attributes = $saved
            ? $saved->only([
                'label', 'recipient_name', 'recipient_phone', 'company_name',
                'street_address', 'apartment', 'city', 'state', 'country',
                'landmark', 'zip_code', 'map_link',
            ])
            : [
                'label' => null,
                'recipient_name' => $customer->full_name,
                'recipient_phone' => $customer->phone,
                'company_name' => null,
                'street_address' => $data['street_address'] ?? null,
                'apartment' => $data['apartment'] ?? null,
                'country' => $data['country'] ?? 'Nigeria',
                'state' => $data['state'] ?? null,
                'city' => $data['city'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'zip_code' => null,
                'map_link' => null,
            ];

        if (! $saved && $customer->registered_at !== null) {
            $this->saveToAddressBook($customer, $attributes);
        }

        return $customer->deliveryAddresses()->create([
            ...$attributes,
            // The route is resolved per order rather than pinned to the saved
            // address: the same address stays correct if the shop redraws its
            // delivery zones, and a saved address carries no fee of its own.
            'delivery_route_id' => $deliveryRoute?->id,
            'is_default' => false,
            'is_snapshot' => true,
        ]);
    }

    /**
     * Remember a typed-in address, unless the customer already has it.
     *
     * Matched on the three fields that identify a place to a courier — street,
     * city and state — so ordering twice to the same address adds one entry
     * rather than two, and an edit to the apartment or landmark is applied to
     * the entry already there instead of creating a near-duplicate.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveToAddressBook(Customer $customer, array $attributes): void
    {
        $existing = $customer->deliveryAddresses()
            ->where('is_snapshot', false)
            ->where('street_address', $attributes['street_address'])
            ->where('city', $attributes['city'])
            ->where('state', $attributes['state'])
            ->first();

        if ($existing) {
            $existing->update($attributes);

            return;
        }

        $customer->deliveryAddresses()->create([
            ...$attributes,
            'is_default' => ! $customer->deliveryAddresses()->where('is_snapshot', false)->exists(),
            'is_snapshot' => false,
        ]);
    }

    /**
     * The cart this order is placed from — and it has to be the one checkout
     * showed the customer.
     *
     * That is not a detail. The storefront reads its basket through
     * `resolveCart`, which treats the customer's own cart and this browser's
     * guest cart as two halves of one pool and takes the newest of them. This
     * method used to ask a different question: the customer's cart first, on
     * its own, and the *oldest* row at that. A signed-in shopper who had
     * abandoned a cart last month and then filled a fresh basket did not have
     * the two agree — they were shown the new basket and charged for the old
     * one, and the basket on screen was thrown away without a word.
     *
     * Signing in part-way through checkout is the ordinary path, so this is not
     * a corner. The two queries have to give one answer, and the answer is the
     * newest cart in the pool the storefront reads from.
     */
    private function lockCart(Store $store, ?Customer $customer, ?string $checkoutToken, ?string $guestToken): ?Cart
    {
        $base = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($checkoutToken) {
            return (clone $base)->where('checkout_token', $checkoutToken)->lockForUpdate()->first();
        }

        $query = (clone $base)->lockForUpdate();

        if ($customer) {
            $query->where(function ($q) use ($customer, $guestToken) {
                $q->where('user_id', $customer->id);

                // Guarded rather than passed through: `where('col', null)`
                // compiles to `is null`, so an absent guest token would quietly
                // widen this to every ownerless cart in the store instead of
                // adding nothing.
                if ($guestToken !== null) {
                    $q->orWhere('guest_token', $guestToken);
                }
            });
        } elseif ($guestToken === null) {
            return null;
        } else {
            $query->where('guest_token', $guestToken);
        }

        // `latest('id')`, matching `resolveCart` exactly — see the note there
        // for why the tie-break is the id rather than the timestamp.
        return $query->latest('id')->first();
    }
}
