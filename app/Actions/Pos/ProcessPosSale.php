<?php

namespace App\Actions\Pos;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ServiceCharge;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vat;
use App\Services\StockLedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessPosSale
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
        private readonly \App\Services\Accounting\InventoryCostingService $costing,
    ) {}

    public function execute(Store $store, User $staff, PosSession $session, array $data): PosSaleResult
    {
        return DB::transaction(function () use ($store, $staff, $session, $data): PosSaleResult {
            $lockedStore = Store::query()->lockForUpdate()->findOrFail($store->id);

            if (! empty($data['idempotency_key'])) {
                $existingOrder = Order::query()
                    ->where('business_id', $lockedStore->business_id)
                    ->where('store_id', $lockedStore->id)
                    ->where('source', 'pos')
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();

                if ($existingOrder) {
                    return new PosSaleResult($existingOrder->load(['items', 'transactions.paymentMethod']), true);
                }
            }

            $items = collect($data['items'])
                ->groupBy('product_id')
                ->map(fn ($lines, $productId) => [
                    'product_id' => (int) $productId,
                    'quantity' => (int) $lines->sum('quantity'),
                ])
                ->values();

            $products = Product::query()
                ->where('store_id', $lockedStore->id)
                ->where('business_id', $lockedStore->business_id)
                ->whereIn('id', $items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $items->count()) {
                throw new DomainException('One or more products are unavailable in this store.');
            }

            $subtotal = 0.0;
            $tax = 0.0;
            $orderItems = [];

            $vatPercentage = (float) (Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first()?->percentage ?? 0);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $price = (float) $product->amount;
                $itemTotal = $price * $item['quantity'];
                $subtotal += $itemTotal;

                $lineTax = 0.0;
                if ($vatPercentage > 0 && $product->is_taxable) {
                    $lineTax = round($itemTotal * $vatPercentage / 100, 2);
                    $tax += $lineTax;
                }

                $costKobo = $this->costing->costForSale($product, $item['quantity']);

                $orderItems[] = new OrderItem([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $price,
                    'quantity' => $item['quantity'],
                    'subtotal' => $itemTotal,
                    'tax_rate' => $product->is_taxable ? $vatPercentage : 0,
                    'tax_amount' => $lineTax,
                    'cost_kobo' => $costKobo > 0 ? $costKobo : null,
                ]);
            }

            $serviceCharge = ! empty($data['service_charge_id'])
                ? ServiceCharge::query()
                    ->where('store_id', $lockedStore->id)
                    ->where('is_active', true)
                    ->find($data['service_charge_id'])
                : null;
            $serviceChargeAmount = (float) ($serviceCharge?->amount ?? 0);
            $total = round($subtotal + $serviceChargeAmount + $tax, 2);
            $payments = $this->normalizePayments($data, $total);

            $paymentsSum = collect($payments)->sum(fn (array $payment) => (float) $payment['amount']);
            if (abs($paymentsSum - $total) > 0.01) {
                throw new DomainException(
                    'Payment amounts ('.number_format($paymentsSum, 2).') do not match order total ('.number_format($total, 2).').'
                );
            }

            $transferBankIds = collect($payments)
                ->where('method', 'transfer')
                ->pluck('bank_account_id')
                ->filter()
                ->unique();

            if (collect($payments)->contains(fn (array $payment) => $payment['method'] === 'transfer' && empty($payment['bank_account_id']))) {
                throw new DomainException('A bank account is required for bank transfer payments.');
            }

            if ($transferBankIds->isNotEmpty()
                && StoreBank::query()
                    ->where('business_id', $lockedStore->business_id)
                    ->whereIn('id', $transferBankIds)
                    ->count() !== $transferBankIds->count()) {
                throw new DomainException('A selected bank account does not belong to this business.');
            }

            $customer = $this->resolveCustomer($lockedStore, $data);
            $amountTendered = (int) collect($payments)->sum(fn (array $payment) => (int) ($payment['amount_tendered'] ?? 0));

            $order = Order::create([
                'store_id' => $lockedStore->id,
                'user_id' => $lockedStore->user_id,
                'business_id' => $lockedStore->business_id,
                'customer_id' => $customer?->id,
                'source' => 'pos',
                'staff_id' => $staff->id,
                'pos_session_id' => $session->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => $total,
                'service_charge_amount' => $serviceChargeAmount > 0 ? $serviceChargeAmount : null,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'meta' => [
                    'customer_name' => $data['customer_name'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'customer_email' => $data['customer_email'] ?? null,
                    'service_charge_id' => $serviceCharge?->id,
                    'service_charge_name' => $serviceCharge?->name,
                    'amount_tendered' => $amountTendered,
                    'split_payment' => count($payments) > 1 ? collect($payments)->map(fn (array $payment) => [
                        'method' => $payment['method'],
                        'amount' => (float) $payment['amount'],
                    ])->all() : null,
                ],
            ]);

            $order->items()->saveMany($orderItems);
            $this->recordPayments($order, $lockedStore, $payments);
            $this->removeStock($order, $lockedStore, $staff, $items, $products);

            $ledger = app(\App\Services\Accounting\LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postSale($order, $staff->id));

            return new PosSaleResult($order->load(['items', 'transactions.paymentMethod']));
        });
    }

    private function normalizePayments(array $data, float $total): array
    {
        return $data['payments'] ?? [[
            'method' => $data['payment_method'],
            'amount' => $total,
            'amount_tendered' => $data['amount_tendered'] ?? null,
            'paystack_reference' => $data['paystack_reference'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
        ]];
    }

    private function resolveCustomer(Store $store, array $data): ?Customer
    {
        $name = trim((string) ($data['customer_name'] ?? ''));
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        $email = trim((string) ($data['customer_email'] ?? ''));

        if ($name === '' && $phone === '') {
            return null;
        }

        $customerData = [
            'first_name' => $name !== '' ? $name : 'Walk-in',
            'last_name' => '',
            'email' => $email !== '' ? $email : ('pos-'.Str::random(8).'@walkin.local'),
            'status' => 'active',
            'password' => Str::random(32),
            'street_address' => ($data['customer_address'] ?? '') !== '' ? $data['customer_address'] : null,
            'city' => ($data['customer_city'] ?? '') !== '' ? $data['customer_city'] : null,
            'state' => ($data['customer_state'] ?? '') !== '' ? $data['customer_state'] : null,
            'country' => ($data['customer_country'] ?? '') !== '' ? $data['customer_country'] : 'Nigeria',
            'business_id' => $store->business_id,
        ];

        $customer = $email !== '' && ! str_contains($email, '@walkin.local')
            ? Customer::query()->where('business_id', $store->business_id)->where('email', $email)->first()
            : null;

        $customer ??= Customer::firstOrCreate(
            ['phone' => $phone !== '' ? $phone : null, 'business_id' => $store->business_id],
            $customerData
        );

        if ($phone !== '' && $customer->phone !== $phone) {
            $customer->update(['phone' => $phone]);
        }

        return $customer;
    }

    private function recordPayments(Order $order, Store $store, array $payments): void
    {
        $paymentMethods = PaymentMethod::query()
            ->whereIn('code', ['paystack', 'bank_transfer'])
            ->get()
            ->keyBy('code');

        foreach ($payments as $payment) {
            $method = $payment['method'];
            $reference = 'TXN-POS-'.Str::upper(Str::random(10));
            $paymentMethodId = null;
            $storeBankId = null;

            if ($method === 'paystack') {
                $paymentMethodId = $paymentMethods->get('paystack')?->id;
                $reference = $payment['paystack_reference'] ?? $reference;
            } elseif ($method === 'transfer') {
                $paymentMethodId = $paymentMethods->get('bank_transfer')?->id;
                $storeBankId = $payment['bank_account_id'];
            }

            $transaction = Transaction::create([
                'reference' => $reference,
                'order_id' => $order->id,
                'business_id' => $store->business_id,
                'store_bank_id' => $storeBankId,
                'payment_method_id' => $paymentMethodId,
                'amount' => (float) $payment['amount'],
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                'metadata' => [
                    'leg_method' => $method,
                    'amount_tendered' => $payment['amount_tendered'] ?? null,
                    'is_split' => count($payments) > 1,
                ],
            ]);

            $amountInKobo = (int) round((float) $payment['amount'] * 100);
            $balanceBefore = (int) $store->balance;
            $store->creditBalance($amountInKobo);
            $transaction->update([
                'balance_updated_at' => now(),
                'store_balance_before' => $balanceBefore,
                'store_balance_after' => (int) $store->fresh()->balance,
            ]);
        }
    }

    private function removeStock(Order $order, Store $store, User $staff, $items, $products): void
    {
        $stockLocations = StockLocation::query()
            ->where('locationable_type', Store::class)
            ->where('locationable_id', $store->id)
            ->whereIn('product_id', $items->pluck('product_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $quantity = $item['quantity'];

            if ((int) $product->quantity < $quantity) {
                throw new DomainException("Insufficient stock for {$product->name}.");
            }

            $stockLocation = $stockLocations->get($product->id);
            if (! $stockLocation) {
                $stockLocation = StockLocation::create([
                    'product_id' => $product->id,
                    'locationable_type' => Store::class,
                    'locationable_id' => $store->id,
                    'business_id' => $store->business_id,
                    'quantity' => (int) $product->quantity,
                ]);
            }

            if ((int) $stockLocation->quantity < $quantity) {
                throw new DomainException("Insufficient stock for {$product->name} at this store.");
            }

            Product::query()->whereKey($product->id)->decrement('quantity', $quantity);
            $this->stockLedger->recordRemoval(
                $stockLocation,
                $quantity,
                $order,
                $staff,
                'POS sale — Order #'.$order->order_number
            );
        }
    }
}
