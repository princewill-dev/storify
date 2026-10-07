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
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Storefront\CheckoutRepository;
use App\Services\Storefront\CheckoutPaymentService;
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
    ) {}

    public function paymentMethods(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        return $this->ok([
            'payment_methods' => CheckoutPaymentMethodResource::collection(
                $this->repository->paymentMethodsFor($store)
            )->resolve($request),
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

        try {
            $order = $placeOrder->execute($store, $customer, $request->validated(), $guestToken, $request->ip());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok([
            'order' => $this->orderPayload($request, $order, $store),
        ], 'Order placed. Proceed to payment.', 201);
    }

    public function paystackInitialize(InitializePaymentRequest $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();

        $order = $this->repository->findOrderByNumber($store, $data['order_number']);

        $remaining = $order->remainingBalance();

        if ($remaining <= 0) {
            return $this->error('This order is already fully paid.', 409);
        }

        $amount = isset($data['amount']) ? min((float) $data['amount'], $remaining) : $remaining;

        if ($amount <= 0) {
            return $this->error('Invalid payment amount.', 422);
        }

        $outcome = $this->payments->initialize($store, $order, $amount, $data);

        if ($outcome->unexpectedFailure) {
            return $this->error('Unable to initialize payment.', 500);
        }

        if (! $outcome->initialized) {
            return $this->error($outcome->gatewayMessage ?? 'Payment initialization failed.', 502);
        }

        return $this->ok([
            'authorization_url' => $outcome->authorizationUrl,
            'reference' => $outcome->reference,
            'amount' => $outcome->amount,
        ], 'Payment initialized.');
    }

    public function paystackVerify(VerifyPaymentRequest $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();

        $transaction = $this->repository->findTransactionByReference($store, $data['reference']);

        $outcome = $this->payments->verify($store, $transaction, $data['reference']);

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

    public function bankTransfer(BankTransferRequest $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();

        $order = $this->repository->findOrderByNumber($store, $data['order_number']);

        $amount = min((float) $data['amount'], $order->remainingBalance());

        if ($amount <= 0) {
            return $this->error('This order is already fully paid.', 409);
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
                'is_partial' => $amount < $order->remainingBalance(),
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
