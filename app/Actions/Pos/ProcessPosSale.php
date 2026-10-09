<?php

namespace App\Actions\Pos;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\InventoryCostingService;
use App\Services\Accounting\LedgerPostingService;
use App\Services\StockLedgerService;
use App\Support\Payments\PaymentGatewayRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessPosSale
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
        private readonly InventoryCostingService $costing,
        private readonly PricePosSale $pricer,
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

            // Pricing lives in PricePosSale: the till's payment-initialize
            // endpoint quotes the same basket through the same arithmetic, so
            // the figure charged to a card and the figure the order is written
            // for cannot disagree. `lock: true` because this call is about to
            // de-stock the very rows it prices.
            $quote = $this->pricer->execute(
                $lockedStore,
                $data['items'],
                $data['service_charge_id'] ?? null,
                lock: true,
            );

            $subtotal = $quote->subtotal;
            $tax = $quote->tax;
            $serviceCharge = $quote->serviceCharge;
            $serviceChargeAmount = $quote->serviceChargeAmount;
            $total = $quote->total;

            $orderItems = [];
            $items = collect();
            $products = collect();

            foreach ($quote->lines as $line) {
                $product = $line['product'];
                $costKobo = $this->costing->costForSale($product, $line['quantity']);

                $orderItems[] = new OrderItem([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax_amount'],
                    'cost_kobo' => $costKobo > 0 ? $costKobo : null,
                ]);

                // removeStock() works off these two, and both must be the rows
                // the pricer already locked — re-querying would take a second,
                // unlocked read of stock it is about to decrement.
                $items->push(['product_id' => $product->id, 'quantity' => $line['quantity']]);
                $products->put($product->id, $product);
            }

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

            $ledger = app(LedgerPostingService::class);
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

    /**
     * `transfer` is the POS client's older name for what the catalogue calls
     * `bank_transfer`. Kept as an alias because tills in the field still send
     * it, and they are not all updated at once.
     */
    private function normalizeMethod(string $method): string
    {
        return $method === 'transfer' ? 'bank_transfer' : $method;
    }

    /**
     * Whether this method is settled by a provider's hosted checkout, and so
     * owes the till a reference proving the money moved.
     *
     * Asked of the catalogue rather than matched against a list of names: this
     * is the same `checkoutModeFor` rule the till uses to decide whether to
     * open a provider at all, and the same one `PaymentMethodController` puts
     * on the wire, so the three cannot drift. `bank_transfer` is `offline` — a
     * person confirms it — and cash is not a provider at all.
     */
    private function isGatewayMethod(string $code): bool
    {
        return PaymentGatewayRegistry::has($code)
            && PaymentGatewayRegistry::checkoutModeFor($code) === 'redirect';
    }

    private function recordPayments(Order $order, Store $store, array $payments): void
    {
        // Look up whatever the till sent rather than a fixed pair, so a provider
        // a business connects actually records against its own method. The
        // hard-coded `['paystack', 'bank_transfer']` meant a new gateway's sales
        // were written with a null payment_method_id — recorded, but
        // unattributable.
        $codes = collect($payments)
            ->map(fn (array $payment): string => $this->normalizeMethod((string) $payment['method']))
            ->push('bank_transfer')
            ->unique()
            ->values()
            ->all();

        $paymentMethods = PaymentMethod::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        foreach ($payments as $payment) {
            $method = (string) $payment['method'];
            $code = $this->normalizeMethod($method);
            $reference = 'TXN-POS-'.Str::upper(Str::random(10));
            $paymentMethodId = $paymentMethods->get($code)?->id;
            $storeBankId = null;

            if ($code === 'bank_transfer') {
                $storeBankId = $payment['bank_account_id'];
            }

            $providedReference = $payment['reference'] ?? $payment['paystack_reference'] ?? null;

            // A gateway leg is only ever settled by money the provider actually
            // collected. Before this guard a `paystack` leg was written exactly
            // like cash — a confirmed transaction and a credited balance for a
            // charge that was never made, so the till printed a receipt for
            // money it had not taken. `PosPaymentService` verifies the reference
            // with the provider before this action is reached; this is the
            // backstop that makes an unverified one impossible to write, not
            // merely unlikely.
            if ($this->isGatewayMethod($code)) {
                if (empty($providedReference)) {
                    throw new DomainException(
                        ($paymentMethods->get($code)?->name ?? ucfirst($code)).' payment has not been completed.'
                    );
                }

                if (Transaction::query()->where('reference', $providedReference)->exists()) {
                    throw new DomainException('That payment reference has already been recorded.');
                }
            }

            if (! empty($providedReference)) {
                $reference = $providedReference;
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
