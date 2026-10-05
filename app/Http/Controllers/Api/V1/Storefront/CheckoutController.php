<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Actions\Checkout\PlaceStorefrontOrder;
use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends ApiController
{
    use ResolvesStorefrontContext;

    public function __construct(private readonly PaystackService $paystack) {}

    public function paymentMethods(string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $methods = $store->paymentMethods()
            ->wherePivot('is_active', true)
            ->get()
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
                'type' => $method->type,
                'description' => $method->description,
            ])->values()->all();

        if (empty($methods)) {
            $methods = PaymentMethod::active()->get()->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
                'type' => $method->type,
                'description' => $method->description,
            ])->values()->all();
        }

        return $this->ok([
            'payment_methods' => $methods,
            'bank_accounts' => $store->assignedBanks()->where('is_verified', true)->get()->map(fn ($bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'account_number' => $bank->account_number,
                'account_name' => $bank->account_name,
            ])->values()->all(),
        ]);
    }

    public function place(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $customer = $this->currentCustomer($request);
        $guestToken = $this->guestToken($request);
        $cart = $this->resolveCart($store, $request, create: false);

        if (! $cart || $cart->items()->count() === 0) {
            return $this->error('Your cart is empty.', 422);
        }

        $requiresShipping = $cart->items()->whereHas('product', fn ($q) => $q->where('is_digital', false))->exists();

        $rules = [
            'notes' => ['nullable', 'string'],
            'delivery_route_id' => ['nullable', 'integer'],
            'email' => [$customer ? 'nullable' : 'required', 'email', 'max:255'],
            'first_name' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'last_name' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'phone' => [$customer ? 'nullable' : 'required', 'string', 'max:20'],
        ];

        if ($requiresShipping) {
            $rules['street_address'] = ['required', 'string'];
            $rules['state'] = ['required', 'string', 'max:255'];
            $rules['city'] = ['required', 'string', 'max:255'];
        } else {
            $rules['street_address'] = ['nullable', 'string'];
            $rules['state'] = ['nullable', 'string', 'max:255'];
            $rules['city'] = ['nullable', 'string', 'max:255'];
        }

        $rules['apartment'] = ['nullable', 'string', 'max:255'];
        $rules['country'] = ['nullable', 'string', 'max:255'];
        $rules['landmark'] = ['nullable', 'string', 'max:255'];

        $data = $request->validate($rules);

        try {
            $order = app(PlaceStorefrontOrder::class)
                ->execute($store, $customer, $data, $guestToken, $request->ip());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok([
            'order' => $this->orderPayload($order, $store),
        ], 'Order placed. Proceed to payment.', 201);
    }

    public function paystackInitialize(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validate([
            'order_number' => ['required', 'string'],
            'callback_url' => ['nullable', 'url', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $order = Order::where('store_id', $store->id)->where('order_number', $data['order_number'])->firstOrFail();

        $remaining = $order->remainingBalance();

        if ($remaining <= 0) {
            return $this->error('This order is already fully paid.', 409);
        }

        $amount = isset($data['amount']) ? min((float) $data['amount'], $remaining) : $remaining;

        if ($amount <= 0) {
            return $this->error('Invalid payment amount.', 422);
        }

        $this->useStorePaystackKeys($store);

        $reference = $this->paystack->generateReference('API');
        $email = $order->customer?->email ?: $data['email'] ?? null;

        if (! $email || str_contains($email, '@walkin.local')) {
            $email = config('mail.from.address', 'no-reply@storify.test');
        }

        DB::beginTransaction();

        try {
            $transaction = Transaction::create([
                'reference' => $reference,
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'amount' => $amount,
                'currency' => 'NGN',
                'status' => TransactionStatus::PENDING,
                'metadata' => [
                    'order_number' => $order->order_number,
                    'is_partial' => $amount < $remaining,
                    'source' => 'storefront_api',
                ],
            ]);

            $result = $this->paystack->initializePayment([
                'email' => $email,
                'amount' => (int) round($amount * 100),
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => $data['callback_url'] ?? url('/'),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'transaction_id' => $transaction->id,
                ],
            ]);

            if (! ($result['success'] ?? false)) {
                DB::rollBack();

                return $this->error($result['message'] ?? 'Payment initialization failed.', 502);
            }

            $transaction->update(['gateway_response' => $result['data']]);
            DB::commit();

            return $this->ok([
                'authorization_url' => $result['data']['authorization_url'],
                'reference' => $reference,
                'amount' => $amount,
            ], 'Payment initialized.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('storefront_api.paystack_initialize_failed', ['error' => $e->getMessage(), 'order' => $data['order_number']]);

            return $this->error('Unable to initialize payment.', 500);
        }
    }

    public function paystackVerify(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validate(['reference' => ['required', 'string']]);

        $transaction = Transaction::where('reference', $data['reference'])
            ->whereHas('order', fn ($q) => $q->where('store_id', $store->id))
            ->firstOrFail();

        $this->useStorePaystackKeys($store);

        $verification = $this->paystack->doubleVerifyPayment($data['reference']);

        $order = $transaction->order;

        if (($verification['success'] ?? false) && strtolower((string) ($verification['data']['status'] ?? '')) === 'success') {
            DB::transaction(function () use ($transaction, $order, $verification) {
                $transaction->update([
                    'status' => TransactionStatus::CONFIRMED->value,
                    'gateway_reference' => $verification['data']['id'] ?? null,
                    'gateway_response' => $verification['data'],
                    'paid_at' => now(),
                ]);

                $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                if ($order->isFullyPaid() && $order->status === OrderStatus::PENDING) {
                    $order->status = OrderStatus::ACCEPTED;
                }

                $order->save();

                $storeModel = $order->store;

                if ($storeModel) {
                    $amountKobo = (int) round((float) $transaction->amount * 100);
                    $before = (int) $storeModel->balance;
                    $storeModel->creditBalance($amountKobo);
                    $transaction->update([
                        'balance_updated_at' => now(),
                        'store_balance_before' => $before,
                        'store_balance_after' => (int) $storeModel->fresh()->balance,
                    ]);
                }
            });

            $ledger = app(LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postPaymentReceived($transaction, null));

            if ($order->isFullyPaid()) {
                app(DigitalDeliveryService::class)->deliverSafely($order);
            }

            $order->refresh();

            return $this->ok([
                'order' => $this->orderPayload($order, $store),
                'fully_paid' => $order->isFullyPaid(),
                'downloads' => $this->downloadsPayload($order),
            ], $order->isFullyPaid() ? 'Payment successful.' : 'Partial payment received.');
        }

        return $this->error($verification['message'] ?? 'Payment could not be verified.', 402);
    }

    public function bankTransfer(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validate([
            'order_number' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'store_bank_id' => ['nullable', 'integer'],
            'payment_slip' => ['nullable', 'file', 'mimes:jpeg,png,jpg,heic,pdf', 'max:5120'],
        ]);

        $order = Order::where('store_id', $store->id)->where('order_number', $data['order_number'])->firstOrFail();

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
            'metadata' => ['source' => 'storefront_api', 'payment_method' => 'bank_transfer'],
        ]);

        return $this->ok([
            'transaction' => ['reference' => $transaction->reference, 'amount' => $amount, 'status' => 'pending'],
        ], 'Payment slip submitted. Your payment will be confirmed shortly.', 201);
    }

    public function orderShow(string $store, string $orderNumber): JsonResponse
    {
        $store = $this->resolveStore($store);

        $order = Order::where('store_id', $store->id)
            ->where('order_number', $orderNumber)
            ->with(['items', 'transactions'])
            ->firstOrFail();

        return $this->ok([
            'order' => $this->orderPayload($order, $store, detailed: true),
            'downloads' => $this->downloadsPayload($order),
        ]);
    }

    private function useStorePaystackKeys(Store $store): void
    {
        $gateway = $store->paymentMethods()->where('code', 'paystack')->first();
        $keys = $gateway?->pivot?->api_keys ?? [];

        if (is_string($keys)) {
            $keys = json_decode($keys, true) ?: [];
        }

        if (! empty($keys['secret_key'])) {
            $this->paystack->usingGateway((object) [
                'secret_key' => $keys['secret_key'],
                'public_key' => $keys['public_key'] ?? '',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order, Store $store, bool $detailed = false): array
    {
        $data = [
            'order_number' => $order->order_number,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $order->payment_status?->value,
            'store' => ['name' => $store->name, 'slug' => $store->slug],
            'created_at' => $order->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['customer_email'] = $order->customer?->email;
            $data['items'] = $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all();
            $data['transactions'] = $order->transactions->map(fn (Transaction $transaction) => [
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
                'paid_at' => $transaction->paid_at?->toISOString(),
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function downloadsPayload(Order $order): array
    {
        if (! $order->isFullyPaid()) {
            return [];
        }

        return $order->digitalDownloads()
            ->with('product')
            ->get()
            ->filter(fn ($download) => $download->product !== null)
            ->map(fn ($download) => [
                'product_name' => $download->product->name,
                'token' => $download->token,
                'downloads_remaining' => $download->downloadsRemaining(),
                'expires_at' => $download->expires_at?->toISOString(),
                'status' => $download->status_label,
            ])->values()->all();
    }
}
