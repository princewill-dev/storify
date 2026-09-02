<?php

namespace App\Http\Controllers\Checkout;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\SelectPaymentMethodRequest;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\PaystackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaystackService $paystack) {}

    public function show(string $store_subdomain, Order $order): View
    {
        $store = $this->storeForOrder($store_subdomain, $order);
        $order->load(['customer', 'items.product', 'transactions']);
        $paymentMethods = $store->paymentMethods()->wherePivot('is_active', true)->get();
        $paymentAmount = $order->remainingBalance();

        return view('storefront.pages.select-payment-method', [
            'store' => $store,
            'order' => $order,
            'paymentMethods' => $paymentMethods,
            'paymentAmount' => $paymentAmount,
            'paymentAttemptKey' => (string) Str::uuid(),
        ]);
    }

    public function select(SelectPaymentMethodRequest $request, string $store_subdomain, Order $order): RedirectResponse
    {
        $store = $this->storeForOrder($store_subdomain, $order);
        $remainingBalance = $order->remainingBalance();

        if ($remainingBalance <= 0) {
            return back()->with('error', 'This order has already been fully paid.');
        }

        $data = $request->validated();
        $amount = (float) ($data['amount'] ?? $remainingBalance);
        if ($amount > $remainingBalance) {
            return back()->withErrors(['amount' => 'The payment amount cannot exceed the remaining balance.'])->withInput();
        }

        $method = $store->paymentMethods()
            ->wherePivot('is_active', true)
            ->where('payment_methods.code', $data['payment_method'])
            ->first();

        if (! $method) {
            return back()->with('error', 'That payment method is not available for this store.');
        }

        $existing = Transaction::query()
            ->where('business_id', $order->business_id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            $authorizationUrl = data_get($existing->metadata, 'authorization_url');

            return $authorizationUrl
                ? redirect()->away($authorizationUrl)
                : $this->bankTransferRedirect($store_subdomain, $order);
        }

        return $method->code === 'paystack'
            ? $this->initializePaystack($order, $store, $method, $amount, $data['idempotency_key'])
            : $this->createBankTransfer($store_subdomain, $order, $method, $amount, $data['idempotency_key']);
    }

    private function initializePaystack(
        Order $order,
        Store $store,
        PaymentMethod $method,
        float $amount,
        string $idempotencyKey,
    ): RedirectResponse {
        $customer = $order->customer;
        if (! $customer || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'A valid customer email is required for online payment.');
        }

        $reference = $this->paystack->generateReference('ORD');
        $transaction = Transaction::create([
            'order_id' => $order->id,
            'business_id' => $order->business_id,
            'payment_method_id' => $method->id,
            'reference' => $reference,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => TransactionStatus::PENDING,
            'metadata' => ['initializing' => true, 'is_partial' => $amount < $order->remainingBalance()],
        ]);

        $result = $this->paystack->initializePayment([
            'email' => $customer->email,
            'amount' => (int) round($amount * 100),
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => route('payment.paystack.callback'),
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $customer->name,
                'store_slug' => $store->slug,
            ],
        ]);

        if (! $result['success']) {
            $transaction->update([
                'status' => TransactionStatus::CANCELED,
                'metadata' => ['initializing' => false, 'failure_message' => $result['message'] ?? 'Initialization failed'],
            ]);

            return back()->with('error', $result['message'] ?? 'Payment initialization failed.');
        }

        $transaction->update(['metadata' => [
            'authorization_url' => $result['data']['authorization_url'],
            'access_code' => $result['data']['access_code'],
            'is_partial' => $amount < $order->remainingBalance(),
        ]]);

        return redirect()->away($result['data']['authorization_url']);
    }

    private function createBankTransfer(
        string $storeSlug,
        Order $order,
        PaymentMethod $method,
        float $amount,
        string $idempotencyKey,
    ): RedirectResponse {
        Transaction::create([
            'order_id' => $order->id,
            'business_id' => $order->business_id,
            'payment_method_id' => $method->id,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'metadata' => [
                'payment_type' => 'bank_transfer',
                'is_partial' => $amount < $order->remainingBalance(),
            ],
        ]);

        Log::info('payment_method_selected', [
            'order_id' => $order->id,
            'business_id' => $order->business_id,
            'payment_method' => 'bank_transfer',
            'amount' => $amount,
        ]);

        return $this->bankTransferRedirect($storeSlug, $order);
    }

    private function bankTransferRedirect(string $storeSlug, Order $order): RedirectResponse
    {
        $route = app()->environment('local') ? 'local.payment.bank-transfer' : 'payment.bank-transfer';

        return redirect()->route($route, ['store_subdomain' => $storeSlug, 'order' => $order]);
    }

    private function storeForOrder(string $slug, Order $order): Store
    {
        return Store::query()
            ->whereKey($order->store_id)
            ->where('slug', $slug)
            ->where('status', Store::STATUS_ACTIVE)
            ->firstOrFail();
    }
}
