<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Actions\Checkout\PlaceStorefrontOrder;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Http\Requests\Storefront\BankTransferRequest;
use App\Http\Requests\Storefront\InitializePaymentRequest;
use App\Http\Requests\Storefront\PlaceOrderRequest;
use App\Http\Requests\Storefront\VerifyPaymentRequest;
use App\Http\Resources\Storefront\CheckoutBankAccountResource;
use App\Http\Resources\Storefront\CheckoutDownloadResource;
use App\Http\Resources\Storefront\CheckoutOrderDetailResource;
use App\Http\Resources\Storefront\CheckoutOrderResource;
use App\Http\Resources\Storefront\CheckoutPaymentMethodResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Storefront\CheckoutPaymentService;
use App\Support\Money\StoreCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Storefront checkout: the payment options, order placement, the two Paystack
 * steps and the bank-transfer slip.
 *
 * Layering: the HTTP contract — the 409/422/502/500 refusals, the message
 * strings, the status codes and the envelope — stays here; the payload rules
 * live in the Storefront FormRequests, every store-scoped read (orders,
 * transactions, payment options, downloads) in CheckoutRepository, the
 * Paystack money movement with its transaction boundaries in
 * CheckoutPaymentService, and the response shapes in the Checkout* resources,
 * moved verbatim so field names, types and order are unchanged.
 *
 * `place` keeps calling the existing PlaceStorefrontOrder action: its
 * multi-table write and transaction already live there, so no service wraps
 * it. `bankTransfer` keeps its single INSERT in the body — one row, no
 * transaction boundary, ledger, mail or notification to coordinate — and the
 * three-scalar initialize response has no model to shape, so neither got an
 * invented layer.
 *
 * PlaceOrderRequest resolves the store and cart the same way the controller
 * does (through the shared ResolvesStorefrontContext concern) because its
 * rules are conditional on that server state — which cart, which products,
 * whether a customer session is present — and it returns no rules while the
 * cart cannot be checked out, so the controller's "Your cart is empty." 422
 * still answers a malformed payload exactly as before.
 *
 * MONEY — the two kobo conversions this controller carried inline were
 * (int) round($amount * 100); both now call Naira::koboFromRounded, whose
 * contract is that exact expression. The amounts written to the transaction
 * rows are unchanged naira floats.
 *
 * Provenance kept with the code it explains:
 *  - the bank-transfer `is_partial` metadata: a part-payment must be
 *    distinguishable from a settled transfer (the Paystack branch already
 *    recorded it; this branch did not);
 *  - the cart-empty 422 is a state guard in the body, still ahead of the
 *    action and behind the (now earlier) validation;
 *  - the transaction lookup for verify is scoped through the order's
 *    store_id, so a reference from another store 404s.
 */
class CheckoutController extends ApiController
{
    use ResolvesStorefrontContext;

    public function __construct(
        private readonly CheckoutRepository $repository,
        private readonly CheckoutPaymentService $payments,
        // Only for one thing: saying *why* there is nothing to pay with. An empty
        // options list used to be self-explanatory — the store had connected no
        // provider — and under a system processor it is not: the store may have
        // connected several and still have nothing to offer, and a customer
        // staring at a dead checkout deserves the sentence rather than a blank.
        private readonly PaymentGatewayResolver $gateways,
    ) {}

    public function paymentMethods(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $currency = StoreCurrency::for($store);

        $methods = $this->repository->paymentMethodsFor($store);

        return $this->ok([
            'store_currency' => $currency,
            // The store's own part-payment switch, so the payment page can
            // decide what to draw before it has an order to draw from. Read here
            // rather than by a call of its own: this is already the one request
            // the payment step makes about paying.
            'allow_part_payment' => (bool) $store->allow_part_payment,
            // Null in the ordinary case, and that is deliberate rather than
            // unfinished: with no system processor set, nothing here has changed
            // and a new field with a new message would be a change to a
            // deployment that did not ask for one.
            'unavailable_reason' => $methods->isEmpty() ? $this->gateways->storeUnavailableReason($store) : null,
            'payment_methods' => $methods
                // Each entry is built with the store's currency, which is what
                // tells it whether it has to show a converted figure.
                ->map(fn (PaymentMethod $method): array => (new CheckoutPaymentMethodResource($method, $currency))
                    ->resolve($request))
                ->values()
                ->all(),
            'bank_accounts' => CheckoutBankAccountResource::collection(
                $this->repository->verifiedBankAccountsFor($store)
            )->resolve($request),
        ]);
    }

    public function place(PlaceOrderRequest $request, PlaceStorefrontOrder $placeOrder, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $customer = $this->currentCustomer($request);
        $guestToken = $this->guestToken($request);
        $cart = $this->resolveCart($store, $request, create: false);

        if (! $cart || $cart->items()->count() === 0) {
            return $this->error('Your cart is empty.', 422);
        }

        $refusal = $this->accountRequirement($customer);

        if ($refusal) {
            return $refusal;
        }

        try {
            $order = $placeOrder->execute($store, $customer, $request->validated(), $guestToken, $request->ip());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok([
            'order' => $this->orderPayload($request, $order, $store),
        ], 'Order placed. Proceed to payment.', 201);
    }

    /**
     * Whether this checkout may proceed, when accounts are required.
     *
     * Read from config rather than enforced by middleware, and staged rather
     * than switched on with the code: the storefront in production places
     * account-less orders today, and it deploys on push while this API deploys
     * by hand. A 401 from middleware would leave that storefront with nothing
     * to say; this answers in the payload's own error envelope, so a bundle
     * too old to know about accounts can still surface a real sentence.
     *
     * Returns null when the order may go ahead — which is every request while
     * the flag is off, including with no customer at all.
     */
    private function accountRequirement(?Customer $customer): ?JsonResponse
    {
        if (! config('storefront.checkout_requires_account')) {
            return null;
        }

        if (! $customer) {
            return $this->error(
                'Please create an account or sign in to complete your order.',
                403,
                ['checkout' => ['account_required']]
            );
        }

        if ($customer->email_verified_at === null) {
            return $this->error(
                'Please verify your email address to complete your order.',
                403,
                ['checkout' => ['email_unverified']]
            );
        }

        if ($customer->status !== Customer::STATUS_ACTIVE) {
            return $this->error(
                'This account cannot place orders. Please contact support.',
                403,
                ['checkout' => ['account_inactive']]
            );
        }

        return null;
    }

    /**
     * Whether this store permits paying less than the outstanding balance.
     *
     * The rule is deliberately one-sided. A partial payment needs
     * `allow_part_payment`; clearing the balance does not. With the setting
     * defaulting to off, the other reading would strand every customer who had
     * already part-paid before their store's switch was off — money taken,
     * order uncompletable — so "pay the rest" stays open at all times and only
     * "pay some of the rest" is gated.
     *
     * The refusal names the figure rather than just denying: the caller is a
     * customer who has already chosen, and "not allowed" without the amount
     * leaves them guessing what to send instead.
     */
    private function refusePartialIfDisallowed(Store $store, float $amount, float $remaining): ?JsonResponse
    {
        if ($store->allow_part_payment || $amount >= $remaining) {
            return null;
        }

        return $this->error(
            sprintf(
                'This store takes full payment only. Please pay the outstanding balance of %s %s.',
                StoreCurrency::for($store),
                number_format($remaining, 2),
            ),
            422,
            ['checkout' => ['part_payment_disabled']],
        );
    }

    /**
     * Start a payment through whichever provider the store offers.
     *
     * `paystackInitialize` is kept as an alias below so the already-deployed
     * storefront keeps working while the new path rolls out; the provider is
     * "paystack" for that route by definition.
     */
    public function initializePayment(InitializePaymentRequest $request, string $store, string $provider): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();

        $order = $this->repository->findOrderByNumber(
            $store,
            $data['order_number'],
            customer: $this->currentCustomer($request),
        );

        $remaining = $order->remainingBalance();

        if ($remaining <= 0) {
            return $this->error('This order is already fully paid.', 409);
        }

        $amount = isset($data['amount']) ? min((float) $data['amount'], $remaining) : $remaining;

        if ($amount <= 0) {
            return $this->error('Invalid payment amount.', 422);
        }

        $refusal = $this->refusePartialIfDisallowed($store, $amount, $remaining);

        if ($refusal) {
            return $refusal;
        }

        $outcome = $this->payments->initialize($store, $order, $amount, $data, $provider);

        if ($outcome->unexpectedFailure) {
            return $this->error('Unable to initialize payment.', 500);
        }

        if (! $outcome->initialized) {
            return $this->error($outcome->gatewayMessage ?? 'Payment initialization failed.', 502);
        }

        return $this->ok([
            // The client branches on this rather than recognising provider
            // names: `redirect` means send the customer to the URL, `offline`
            // means render the instructions below.
            'mode' => $outcome->mode,
            'authorization_url' => $outcome->authorizationUrl,
            'instructions' => $outcome->instructions,
            'reference' => $outcome->reference,
            'amount' => $outcome->amount,
            // Empty unless the provider charges in another currency, and the
            // converted figure the customer is agreeing to when it does.
            'pricing' => $outcome->pricing,
        ], $outcome->mode === 'offline' ? 'Follow the instructions to pay.' : 'Payment initialized.');
    }

    public function paystackInitialize(InitializePaymentRequest $request, string $store): JsonResponse
    {
        return $this->initializePayment($request, $store, 'paystack');
    }

    public function verifyPayment(VerifyPaymentRequest $request, string $store, string $provider): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();

        $transaction = $this->repository->findTransactionByReference(
            $store,
            $data['reference'],
            $this->currentCustomer($request),
        );

        $outcome = $this->payments->verify($store, $transaction, $data['reference'], $provider);

        if (! $outcome->verified) {
            return $this->error($outcome->message ?? 'Payment could not be verified.', 402);
        }

        $order = $outcome->order;

        return $this->ok([
            'order' => $this->orderPayload($request, $order, $store),
            'fully_paid' => $order->isFullyPaid(),
            'downloads' => $this->downloadsPayload($request, $order),
        ], $order->isFullyPaid() ? 'Payment successful.' : 'Partial payment received.');
    }

    public function paystackVerify(VerifyPaymentRequest $request, string $store): JsonResponse
    {
        return $this->verifyPayment($request, $store, 'paystack');
    }

    public function bankTransfer(BankTransferRequest $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();

        $order = $this->repository->findOrderByNumber(
            $store,
            $data['order_number'],
            customer: $this->currentCustomer($request),
        );

        $remaining = $order->remainingBalance();

        $amount = min((float) $data['amount'], $remaining);

        if ($amount <= 0) {
            return $this->error('This order is already fully paid.', 409);
        }

        $refusal = $this->refusePartialIfDisallowed($store, $amount, $remaining);

        if ($refusal) {
            return $refusal;
        }

        $path = $request->hasFile('payment_slip')
            ? $request->file('payment_slip')->store('payment-slips', 'public')
            : null;

        $transaction = Transaction::create([
            'reference' => 'BT-'.strtoupper(Str::random(12)),
            'order_id' => $order->id,
            'business_id' => $order->business_id,
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => TransactionStatus::PENDING,
            'payment_slip' => $path,
            'store_bank_id' => $data['store_bank_id'] ?? null,
            // is_partial marks a part-payment so the management side can tell it
            // apart from a full bank transfer. The Paystack branch above already
            // records it; this branch did not, so a partial bank transfer looked
            // identical to a settled one.
            'metadata' => [
                'source' => 'storefront_api',
                'payment_method' => 'bank_transfer',
                'is_partial' => $amount < $remaining,
            ],
        ]);

        return $this->ok([
            'transaction' => ['reference' => $transaction->reference, 'amount' => $amount, 'status' => 'pending'],
        ], 'Payment slip submitted. Your payment will be confirmed shortly.', 201);
    }

    public function orderShow(Request $request, string $store, string $orderNumber): JsonResponse
    {
        $store = $this->resolveStore($store);

        $order = $this->repository->findOrderByNumber($store, $orderNumber, ['items', 'transactions']);

        return $this->ok([
            'order' => $this->orderPayload($request, $order, $store, detailed: true),
            'downloads' => $this->downloadsPayload($request, $order),
        ]);
    }

    /**
     * The order payload the place/verify/track responses share — the inline
     * map moved to the Storefront resources verbatim.
     *
     * @return array<string, mixed>
     */
    private function orderPayload(Request $request, Order $order, Store $store, bool $detailed = false): array
    {
        $resource = $detailed
            ? new CheckoutOrderDetailResource($order, $store)
            : new CheckoutOrderResource($order, $store);

        return $resource->resolve($request);
    }

    /**
     * Downloads are an entitlement: only a fully paid order exposes them. The
     * orphan-product filter lives in the repository; values() keeps the
     * payload a JSON array, as the inline ->values()->all() did after filter()
     * had preserved the original keys.
     *
     * @return array<int, array<string, mixed>>
     */
    private function downloadsPayload(Request $request, Order $order): array
    {
        if (! $order->isFullyPaid()) {
            return [];
        }

        return CheckoutDownloadResource::collection(
            $this->repository->downloadsFor($order)->values()
        )->resolve($request);
    }
}
