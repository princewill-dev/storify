<?php

namespace App\Actions\Pos;

use App\Enums\ProviderFeeBearer;
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
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Custody\InstantPayoutService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\StockLedgerService;
use App\Support\Money\Naira;
use App\Support\Money\StoreCurrency;
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
        private readonly PaymentGatewayResolver $gateways,
    ) {}

    /**
     * @param  array<string, int>  $providerFees  the provider's own charge per gateway
     *                                            reference, as reported by the verification
     *                                            the checkout ran moments ago. Absent for a
     *                                            leg whose provider did not report one, and
     *                                            for every caller that never asked — which is
     *                                            why the default is empty rather than the
     *                                            parameter being required.
     */
    public function execute(Store $store, User $staff, PosSession $session, array $data, array $providerFees = []): PosSaleResult
    {
        // Collect on a till leg is recorded inside the transaction below, but the
        // payout it should trigger cannot be: `sweepBusiness()` writes a payout
        // row and debits a wallet, and it calls the provider. Doing that inside
        // this transaction would hold its row locks across an HTTP round trip, so
        // the legs are gathered here and offered for payout once it has committed.
        $collected = [];

        $result = DB::transaction(function () use ($store, $staff, $session, $data, $providerFees, &$collected): PosSaleResult {
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

            // Normalised before the comparison, not after: these checks used to
            // match the literal `transfer`, the POS client's older name for what
            // the catalogue calls `bank_transfer`, so a leg sent under the
            // catalogue's own code skipped both of them — no bank account
            // required, and one belonging to another business accepted. Latent
            // while the till sent only the alias; live the moment the till sends
            // the code the catalogue and `PaymentMethodController` both use.
            $isBankTransfer = fn (array $payment): bool => $this->normalizeMethod((string) $payment['method']) === 'bank_transfer';

            $transferBankIds = collect($payments)
                ->filter($isBankTransfer)
                ->pluck('bank_account_id')
                ->filter()
                ->unique();

            if (collect($payments)->contains(fn (array $payment): bool => $isBankTransfer($payment) && empty($payment['bank_account_id']))) {
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
            $managedLegs = $this->recordPayments($order, $lockedStore, $payments, $providerFees);
            $this->removeStock($order, $lockedStore, $staff, $items, $products);

            $ledger = app(LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postSale($order, $staff->id));

            // A till sale posts its money straight to the provider clearing
            // account, so a leg the platform processed on the business's behalf
            // needs the two halves of the commission taken off it — the
            // business's expense and, on the platform's own books, our income.
            // A storefront sale gets both from `postManagedPaymentReceived()`,
            // which this path must not use: there is no receivable here for it
            // to credit, and crediting one would invent an asset that never
            // existed. Bachs' own fee is neither posted nor knowable at this
            // point — see `postManagedCommissionExpense()`.
            $custody = app(CustodyService::class);

            foreach ($managedLegs as $leg) {
                $ledger->safe(fn () => $ledger->postManagedCommissionExpense(
                    $leg['transaction'],
                    $leg['commission_kobo'],
                    $staff->id,
                    custody: $leg['custody'],
                ));

                // Under custody the commission is recognised by the collection
                // entry instead, against the same money — see below.
                if (! $leg['custody']) {
                    $ledger->safe(fn () => $ledger->postPlatformCommissionEarned(
                        $leg['transaction'],
                        $leg['commission_kobo'],
                        $staff->id,
                    ));
                }

                // The till is the third way a sale gets paid, and the only one
                // that never passes through a webhook or a verify callback — so
                // nothing else in the pipeline will ever credit the wallet for
                // it, and a custody business selling at the till would simply
                // never be owed its money.
                //
                // A till leg is priced when the provider reports its charge at
                // the moment the card is read, which for the providers a managed
                // leg can run on it does — so in the ordinary case the wallet
                // credits here and the business is owed its money immediately.
                //
                // When the provider said nothing about its fee, `fee_kobo` is
                // null on the row and this call logs and returns without
                // crediting. That is deliberate: `creditAwaitingSales()`, on the
                // promote pass, is what comes back for those, and crediting a fee
                // we have not been told would understate the debt on a number no
                // one would ever be able to correct.
                if ($leg['custody'] && $custody->recordCollectedSale($leg['transaction'], $leg['provider'])) {
                    $collected[] = $leg['transaction'];
                }
            }

            return new PosSaleResult($order->load(['items', 'transactions.paymentMethod']));
        });

        // Paid out now, in the same request the sale was taken in, rather than at
        // the next tick of the payout run. The restrictions, the balance gate and
        // the reservation are the run's own — see
        // {@see InstantPayoutService} — and it never throws, so a provider that
        // cannot be reached leaves the sale payable for the run to pay instead of
        // failing a sale the cashier has already completed.
        foreach ($collected as $transaction) {
            app(InstantPayoutService::class)->afterCollection($transaction);
        }

        return $result;
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

    /**
     * Write a transaction row per leg and settle each one's effect on the
     * store.
     *
     * Returns the legs the platform processed on the business's behalf, so the
     * caller can post their commission once the sale's own entry exists. A leg
     * charged through the business's own keys — and cash, and a bank transfer —
     * leaves nothing to return.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<string, int>  $providerFees  the provider's reported charge, keyed
     *                                            by the reference the leg carries
     * @return array<int, array{transaction: Transaction, commission_kobo: int}>
     */
    private function recordPayments(Order $order, Store $store, array $payments, array $providerFees = []): array
    {
        $managedLegs = [];

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

            // Whether the platform processed this leg on the business's behalf.
            //
            // Resolved here rather than recorded at initialize, because the till
            // is a client and its word cannot decide where money goes: the
            // gateway leg is only ever written for a payment the provider has
            // already confirmed, so the arrangement is read from the server's own
            // records at the moment the sale is written. The gap is the seconds
            // between the customer tapping their card and the till finishing the
            // sale, so unlike the storefront — where settlement can be days
            // behind the charge — there is no meaningful window for it to have
            // changed underneath us.
            $managed = $code !== '' && $this->isGatewayMethod($code)
                ? $this->gateways->connection((int) $store->business_id, $code, (int) $store->id)
                : null;

            $isManaged = (bool) ($managed?->isManaged());
            $commissionKobo = $isManaged
                ? PlatformCommission::fromSettings()->on((int) round((float) $payment['amount'] * 100))
                : null;

            // Whose money the provider's own charge came out of — the same flag
            // `payment_mode` is written from below, because they are the same
            // question. Held in a local so the row, the ledger and the wallet
            // cannot read it three different ways.
            $feeBearer = $isManaged ? ProviderFeeBearer::PLATFORM : ProviderFeeBearer::BUSINESS;

            // What the provider charged for this leg, when the checkout's own
            // verification reported it.
            //
            // Recorded for a managed leg and only a managed leg. An own-keys
            // leg's provider fee is just as real, but it has never been on the
            // row, and putting it there now would move a Processing Fee that
            // businesses have been reading off their dashboard since before any
            // of this — a reporting change dressed up as a data fix. For a
            // managed leg it is the opposite: the fee is taken off the money
            // before Storify ever holds it, so the column is the only place the
            // business can see what it actually cost, and under custody it is
            // what makes the sale priceable at all.
            $providerFeeKobo = $isManaged && ! empty($providedReference)
                ? ($providerFees[(string) $providedReference] ?? null)
                : null;

            $transaction = Transaction::create([
                'reference' => $reference,
                'order_id' => $order->id,
                'business_id' => $store->business_id,
                'store_bank_id' => $storeBankId,
                'payment_method_id' => $paymentMethodId,
                'amount' => (float) $payment['amount'],
                // The store's currency, because that is the currency `amount`
                // is in. Previously omitted, so the column default ('NGN') was
                // what a USD store got.
                'currency' => StoreCurrency::for($store),
                'status' => TransactionStatus::CONFIRMED,
                'paid_at' => now(),
                // The provider's own id for this leg, when the till echoed one
                // back from initialize. `reference` above is what we issued;
                // this is what the provider calls the charge, and it is the only
                // name a refund or a late webhook can address it by.
                'gateway_reference' => $payment['provider_reference'] ?? null,
                // The commission as a column, not only in the metadata below —
                // the two move together from here on.
                'platform_fee_kobo' => $commissionKobo,
                'provider_fee_bearer' => $feeBearer,
                // `fee_kobo` is written only when the provider reported what it
                // charged, which it does at the moment the customer's card is
                // read — the checkout verifies the charge before this action
                // runs, and the figure it saw is what arrives here. It is our own
                // cost record on a managed leg and the business's deduction on an
                // own-keys one; either way it is kept, and it is what reconciles
                // the provider's balance.
                //
                // When it was not reported, nothing the business is owed is
                // waiting on it. On our keys the fee is ours, so the net is
                // `amount - commission` and is written on the spot — which is
                // what lets the wallet credit the leg now instead of at some
                // later pass that has to find the fee first. On the business's
                // own keys the net stays null: `net` means "reached the
                // business's bank", and a partial subtraction is a wrong number
                // rather than a late one, so the leg waits for the fee — see
                // `CustodyService::creditAwaitingSales()`.
                'fee_kobo' => $providerFeeKobo,
                'net_kobo' => Transaction::netKoboFor(
                    Naira::koboFromRounded((float) $payment['amount']),
                    $providerFeeKobo,
                    (int) ($commissionKobo ?? 0),
                    $feeBearer,
                ),
                'metadata' => array_merge([
                    'leg_method' => $method,
                    'amount_tendered' => $payment['amount_tendered'] ?? null,
                    'is_split' => count($payments) > 1,
                    'payment_mode' => $isManaged ? 'platform_managed' : 'own_keys',
                    'platform_fee_kobo' => $commissionKobo,
                ], $this->chargeRecord($payment)),
            ]);

            // Not for a managed leg: the money is in the business's own account
            // at the provider, already net of its fees, and a balance we hold
            // credited on top of it would be money that is not ours.
            if (! $isManaged) {
                $amountInKobo = (int) round((float) $payment['amount'] * 100);
                $balanceBefore = (int) $store->balance;
                $store->creditBalance($amountInKobo);
                $transaction->update([
                    'balance_updated_at' => now(),
                    'store_balance_before' => $balanceBefore,
                    'store_balance_after' => (int) $store->fresh()->balance,
                ]);
            } else {
                $managedLegs[] = [
                    'transaction' => $transaction,
                    'commission_kobo' => (int) $commissionKobo,
                    // The provider whose settlement window governs this leg,
                    // carried out of the loop because the wallet below needs it
                    // and the loop variable is gone by then.
                    'provider' => $code,
                    // Whether Storify collected it — read off the row that was
                    // just written rather than off the business's switch, because
                    // the switch is what decides how the *next* leg is charged and
                    // an admin can flip it between this line and the postings
                    // below. `managedLegs` holds only legs charged on the
                    // platform's own credentials, and the row records exactly
                    // that, so the two halves of the postings cannot disagree with
                    // the till's own history.
                    //
                    // It used to name `$lockedStore`, the local in `execute()`,
                    // which is not in scope here: every managed leg on this branch
                    // threw "Undefined variable" and the sale died as a 500.
                    // Latent only because a managed till leg is impossible while no
                    // processor is enforced, which is every deployment so far.
                    'custody' => $transaction->isPlatformManaged(),
                ];
            }
        }

        return $managedLegs;
    }

    /**
     * What the provider was asked to charge for this leg, when it differs from
     * what the store is owed.
     *
     * Empty when the till sent no `charge_amount` — cash, offline rails, the
     * six naira providers, and any till older than this API. The storefront
     * writes the equivalent under `metadata.pricing`; this is a separate key
     * because nothing reads it back for comparison, and sharing the name would
     * make it look like the settlement floor applies here when it does not.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, array<string, mixed>>
     */
    private function chargeRecord(array $payment): array
    {
        if (! isset($payment['charge_amount'])) {
            return [];
        }

        return [
            'charge' => [
                'amount_minor' => (int) $payment['charge_amount'],
                'currency' => $payment['charge_currency'] ?? null,
            ],
        ];
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
