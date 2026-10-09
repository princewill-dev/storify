<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Actions\Pos\ProcessPosSale;
use App\Http\Controllers\Api\V1\Pos\Concerns\ResolvesPosTerminal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosCheckoutRequest;
use App\Mail\PosReceiptMail;
use App\Models\Store;
use App\Services\Pos\PosPaymentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class CheckoutController extends Controller
{
    use ResolvesPosTerminal;

    public function __construct(
        private readonly ProcessPosSale $processSale,
        private readonly PosPaymentService $payments,
    ) {}

    public function __invoke(PosCheckoutRequest $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $session = $this->openSessionFor($store, $user);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Please open a POS session first.'], 400);
        }

        $data = $request->validated();

        if (! $this->pinIsValid($user, $data['pin'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        // Anything settled by a provider's checkout has to have been paid, and
        // the provider is asked here rather than trusted. Until this existed a
        // `paystack` leg was written straight through as a confirmed
        // transaction, so the till could complete a sale nobody had paid for.
        // The refusal costs nothing: no order, no stock, no money has moved.
        try {
            $this->payments->assertPaid($store, $data['payments'] ?? []);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        try {
            $result = $this->processSale->execute($store, $user, $session, $data);
        } catch (DomainException $e) {
            $this->logPaidButUnsold($store, $user, $data, $e);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->logPaidButUnsold($store, $user, $data, $e);

            Log::error('pos.checkout_failed', [
                'store_id' => $store->id,
                'staff_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'The sale could not be completed.'], 500);
        }

        $order = $result->order;
        $meta = $order->meta ?? [];

        if (! $result->replayed && ! empty($meta['customer_email'])) {
            try {
                Mail::to($meta['customer_email'])->queue(new PosReceiptMail($order));
            } catch (\Throwable $e) {
                Log::error('pos_receipt_email_failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        $amountTendered = (int) ($meta['amount_tendered'] ?? 0);

        return response()->json([
            'success' => true,
            'replayed' => $result->replayed,
            'data' => [
                'order' => [
                    'order_number' => $order->order_number,
                    'total' => (float) $order->total,
                    'subtotal' => (float) $order->subtotal,
                    'tax' => (float) $order->tax,
                    'amount_tendered' => $amountTendered,
                    'change' => $amountTendered > 0 ? max(0, $amountTendered - (int) round((float) $order->total * 100)) : 0,
                    'date' => $order->created_at->toISOString(),
                    'cashier' => $user?->name,
                    'payment_method' => $order->transactions->count() === 1
                        ? ($order->transactions->first()->metadata['leg_method'] ?? 'cash')
                        : 'split',
                    'payments' => $order->transactions->map(fn ($transaction) => [
                        'method' => $transaction->metadata['leg_method'] ?? ($transaction->paymentMethod?->code ?? 'cash'),
                        'method_label' => $transaction->paymentMethod?->name ?? 'Cash',
                        'amount' => (float) $transaction->amount,
                    ]),
                    'store_name' => $store->name,
                    'store_address' => $store->address,
                    'customer_name' => $meta['customer_name'] ?? null,
                    'customer_phone' => $meta['customer_phone'] ?? null,
                    'notes' => $order->notes,
                    'service_charge_name' => $meta['service_charge_name'] ?? null,
                    'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
                    'items' => $order->items->map(fn ($item) => [
                        'name' => $item->product_name,
                        'qty' => $item->quantity,
                        'price' => (float) $item->unit_price,
                        'subtotal' => (float) $item->subtotal,
                        'tax' => (float) $item->tax_amount,
                    ]),
                ],
            ],
        ], $result->replayed ? 200 : 201);
    }

    /**
     * Record a sale that took the customer's money but could not be written.
     *
     * This is the one genuinely bad outcome of charging a card before the order
     * exists: the provider has the cash and there is nothing here to attach it
     * to, so the only way back is a refund from the provider's dashboard. The
     * pre-flight check in `PosPaymentService::quote` removes the ordinary
     * causes — a product this store does not stock, a quantity it does not have
     * — so what is left is a concurrent sale on the last unit. Nothing can
     * replay it automatically; a log line naming the references is what makes
     * it findable, and it should be.
     */
    private function logPaidButUnsold(Store $store, ?object $user, array $data, \Throwable $e): void
    {
        $charged = collect($data['payments'] ?? [])
            ->filter(fn (array $payment): bool => $this->payments->isGatewayMethod((string) $payment['method']))
            ->filter(fn (array $payment): bool => ! empty($payment['reference'] ?? $payment['paystack_reference'] ?? null))
            ->map(fn (array $payment): array => [
                'method' => $payment['method'],
                'reference' => $payment['reference'] ?? $payment['paystack_reference'],
                'amount' => (float) $payment['amount'],
            ])
            ->values()
            ->all();

        if ($charged === []) {
            return;
        }

        Log::error('pos.gateway_paid_no_order', [
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'staff_id' => $user?->id,
            'charged' => $charged,
            'reason' => $e->getMessage(),
        ]);
    }
}
