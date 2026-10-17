<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\InitializeInvoicePaymentRequest;
use App\Http\Requests\Storefront\InvoiceBankTransferRequest;
use App\Http\Requests\Storefront\VerifyPaymentRequest;
use App\Http\Resources\Storefront\CheckoutBankAccountResource;
use App\Http\Resources\Storefront\CheckoutPaymentMethodResource;
use App\Http\Resources\Storefront\StorefrontInvoiceResource;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Access\StorePaymentGate;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Storefront\InvoicePaymentService;
use App\Support\Money\StoreCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Paying an invoice from the storefront SPA.
 *
 * The invoice number is not the credential — `payment_token` is, and it is
 * resolved by hand rather than by route-model binding because
 * `Invoice::getRouteKeyName()` is `invoice_number`. The lookup is additionally
 * scoped to the store's business and to the invoice's own store (or to a
 * business-level invoice, which any of the business's storefronts may collect).
 *
 * Deliberately outside `ResolvesStorefrontContext`: that trait refuses a store
 * with `has_website` off. An invoice is a bill sent to a person; switching the
 * shop off stops the shop window, not the customer's ability to pay what they
 * owe.
 *
 * The money rules and the state transition live in
 * {@see InvoicePaymentService}; the HTTP contract, the status codes and the
 * sentence a customer reads stay here, as they do for checkout.
 */
class InvoicePaymentController extends ApiController
{
    public function __construct(
        private readonly CheckoutRepository $repository,
        private readonly InvoicePaymentService $payments,
        private readonly StorePaymentGate $paymentGate,
        private readonly PaymentGatewayResolver $gateways,
    ) {}

    public function show(Request $request, string $store, string $token): JsonResponse
    {
        $store = $this->resolveStore($store);
        $invoice = $this->findInvoice($store, $token, ['items', 'business', 'customer', 'transactions']);

        if ($invoice->isDraft()) {
            abort(404);
        }

        $currency = $this->payments->currencyFor($invoice);

        if (($problem = $this->currencyProblem($store, $currency)) !== null) {
            return $this->error($problem, 422);
        }

        $methods = $this->repository->paymentMethodsFor($store);
        $unavailableReason = $this->unavailableReason($store, $invoice, $currency, $methods);

        return $this->ok([
            'invoice' => (new StorefrontInvoiceResource($invoice, $store))->resolve($request),
            'payment_methods' => $methods
                ->map(fn (PaymentMethod $method): array => (new CheckoutPaymentMethodResource($method, $currency))
                    ->resolve($request))
                ->values()
                ->all(),
            'bank_accounts' => CheckoutBankAccountResource::collection(
                $this->repository->verifiedBankAccountsFor($store)
            )->resolve($request),
            'store_currency' => $currency,
            'payable' => $unavailableReason === null,
            'unavailable_reason' => $unavailableReason,
        ]);
    }

    public function initialize(
        InitializeInvoicePaymentRequest $request,
        string $store,
        string $token,
        string $provider,
    ): JsonResponse {
        $store = $this->resolveStore($store);
        $invoice = $this->findInvoice($store, $token, ['business', 'customer']);

        if ($invoice->isDraft()) {
            abort(404);
        }

        // KYC gate, asked at the point money would move rather than at store
        // activation — the same rule every other payment surface uses.
        if (($refusal = $this->paymentGate->refusal($store)) !== null) {
            return $this->error($refusal, 422);
        }

        if (($problem = $this->currencyProblem($store, $this->payments->currencyFor($invoice))) !== null) {
            return $this->error($problem, 422);
        }

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return $this->error('This invoice is no longer accepting payments.', 409);
        }

        $remaining = $invoice->remainingBalance();

        if ($remaining <= 0) {
            return $this->error('This invoice is already fully paid.', 409);
        }

        $data = $request->validated();
        $amount = isset($data['amount']) ? (float) $data['amount'] : $remaining;

        // Refused rather than clamped: the customer typed a figure, and silently
        // charging a different one is how an invoice ends up overpaid.
        if ($amount < 1 || $amount > $remaining) {
            return $this->error('The amount is not valid for this invoice.', 422);
        }

        $outcome = $this->payments->initialize($store, $invoice, $amount, $data, $provider);

        if ($outcome->unexpectedFailure) {
            return $this->error('Unable to initialize payment.', 500);
        }

        if (! $outcome->initialized) {
            return $this->error($outcome->gatewayMessage ?? 'Payment initialization failed.', 502);
        }

        return $this->ok([
            'mode' => $outcome->mode,
            'authorization_url' => $outcome->authorizationUrl,
            'instructions' => $outcome->instructions,
            'reference' => $outcome->reference,
            'amount' => $outcome->amount,
            'pricing' => $outcome->pricing,
        ], $outcome->mode === 'offline' ? 'Follow the instructions to pay.' : 'Payment initialized.');
    }

    public function verify(
        VerifyPaymentRequest $request,
        string $store,
        string $token,
        string $provider,
    ): JsonResponse {
        $store = $this->resolveStore($store);
        $invoice = $this->findInvoice($store, $token, ['items', 'business', 'customer']);

        if ($invoice->isDraft()) {
            abort(404);
        }

        if (($problem = $this->currencyProblem($store, $this->payments->currencyFor($invoice))) !== null) {
            return $this->error($problem, 422);
        }

        $data = $request->validated();

        // Scoped to this invoice and to the provider's own method: a reference
        // from another invoice, another store, or another gateway must not be
        // verifiable here.
        $query = Transaction::query()
            ->where('reference', $data['reference'])
            ->where('invoice_id', $invoice->id);

        $methodId = PaymentMethod::query()->where('code', $provider)->value('id');

        if ($methodId !== null) {
            $query->where('payment_method_id', $methodId);
        }

        $transaction = $query->firstOrFail();

        $outcome = $this->payments->verify($store, $invoice, $transaction, $data['reference'], $provider);

        if (! $outcome->verified) {
            return $this->error($outcome->message ?? 'Payment could not be verified.', 402);
        }

        $invoice = $outcome->invoice->load(['items', 'business', 'customer', 'transactions']);

        return $this->ok([
            'invoice' => (new StorefrontInvoiceResource($invoice, $store))->resolve($request),
            'fully_paid' => $invoice->isFullyPaid(),
        ], $invoice->isFullyPaid() ? 'Payment successful.' : 'Partial payment received.');
    }

    public function bankTransfer(
        InvoiceBankTransferRequest $request,
        string $store,
        string $token,
    ): JsonResponse {
        $store = $this->resolveStore($store);
        $invoice = $this->findInvoice($store, $token, ['business']);

        if ($invoice->isDraft()) {
            abort(404);
        }

        if (($refusal = $this->paymentGate->refusal($store)) !== null) {
            return $this->error($refusal, 422);
        }

        // The offline rail is only offered when the resolver offers it. Under a
        // system processor it does not resolve at all, which is what stops a
        // customer bypassing Storify's collection with a slip.
        if (! isset($this->gateways->forStore($store)['bank_transfer'])) {
            return $this->error('Bank transfer is not available for this store.', 422);
        }

        if (($problem = $this->currencyProblem($store, $this->payments->currencyFor($invoice))) !== null) {
            return $this->error($problem, 422);
        }

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return $this->error('This invoice is no longer accepting payments.', 409);
        }

        $remaining = $invoice->remainingBalance();

        if ($remaining <= 0) {
            return $this->error('This invoice is already fully paid.', 409);
        }

        $data = $request->validated();
        $amount = (float) $data['amount'];

        if ($amount > $remaining) {
            return $this->error('The amount is not valid for this invoice.', 422);
        }

        $path = $request->hasFile('payment_slip')
            ? $request->file('payment_slip')->store('payment-slips', 'public')
            : null;

        $transaction = Transaction::create([
            'reference' => 'INV-BT-'.strtoupper(Str::random(12)),
            'invoice_id' => $invoice->id,
            'business_id' => $invoice->business_id,
            'amount' => $amount,
            'currency' => $this->payments->currencyFor($invoice),
            'status' => TransactionStatus::PENDING,
            'payment_slip' => $path,
            'store_bank_id' => $data['store_bank_id'] ?? null,
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'payment_token' => $invoice->payment_token,
                'source' => 'storefront_api',
                'payment_method' => 'bank_transfer',
                'is_partial' => $amount < $remaining,
            ],
        ]);

        return $this->ok([
            'transaction' => [
                'reference' => $transaction->reference,
                'amount' => $amount,
                'status' => 'pending',
            ],
        ], 'Payment slip submitted. Your payment will be confirmed shortly.', 201);
    }

    /**
     * The storefront named in the URL, without the `has_website` off-switch.
     *
     * Deleted stores still 404. Everything else about the lookup — the slug, the
     * status column — matches the storefront's own resolution.
     */
    private function resolveStore(string $slug): Store
    {
        return Store::query()
            ->where('slug', $slug)
            ->where('status', '!=', 'deleted')
            ->firstOrFail();
    }

    /**
     * The invoice behind the token, reachable only through its own business and
     * (when it has one) its own store.
     *
     * A business-level invoice — `store_id` null — is payable through any of the
     * business's storefronts; the first one to initialize pins itself onto the
     * row. The token alone is still not enough to read somebody else's invoice.
     *
     * @param  array<int, string>  $with
     */
    private function findInvoice(Store $store, string $token, array $with = []): Invoice
    {
        return Invoice::query()
            ->where('payment_token', $token)
            ->where('business_id', $store->business_id)
            ->where(fn ($query) => $query
                ->whereNull('store_id')
                ->orWhere('store_id', $store->id))
            ->with($with)
            ->firstOrFail();
    }

    /**
     * Why the invoice cannot be paid on this storefront, or null when it can.
     *
     * The invoice's currency is the business's and is authoritative. When the
     * storefront prices in a different one, converting would change what the
     * customer owes and crediting the store's balance would credit the wrong
     * unit — so it is refused rather than mispriced.
     */
    private function currencyProblem(Store $store, string $invoiceCurrency): ?string
    {
        $storeCurrency = StoreCurrency::for($store);

        if ($invoiceCurrency === $storeCurrency) {
            return null;
        }

        return "This invoice is issued in {$invoiceCurrency}, but this store charges in {$storeCurrency}. "
            .'It cannot be paid through this storefront.';
    }

    /**
     * The sentence the page shows instead of a payment form, or null when there
     * is a form to show.
     */
    private function unavailableReason(Store $store, Invoice $invoice, string $currency, $methods): ?string
    {
        if (($refusal = $this->paymentGate->refusal($store)) !== null) {
            return $refusal;
        }

        if ($invoice->isFullyPaid() || $invoice->status === InvoiceStatus::PAID) {
            return 'This invoice has been paid in full.';
        }

        if ($invoice->status === InvoiceStatus::VOID) {
            return 'This invoice is no longer accepting payments.';
        }

        if ($methods->isEmpty()) {
            return $this->gateways->storeUnavailableReason($store)
                ?? 'This store has no payment method available right now.';
        }

        return null;
    }
}
