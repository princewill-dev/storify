<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Actions\Pos\ProcessPosSale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosCheckoutRequest;
use App\Mail\PosReceiptMail;
use App\Models\PosSession;
use App\Models\Store;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class CheckoutController extends Controller
{
    public function __construct(private readonly ProcessPosSale $processSale) {}

    public function __invoke(PosCheckoutRequest $request, Store $store): JsonResponse
    {
        $user = $request->user();
        $session = PosSession::query()
            ->where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Please open a POS session first.'], 400);
        }

        $data = $request->validated();
        if ($user->pos_pin && (empty($data['pin']) || ! Hash::check($data['pin'], $user->pos_pin))) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        try {
            $result = $this->processSale->execute($store, $user, $session, $data);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('pos.checkout_failed', [
                'store_id' => $store->id,
                'staff_id' => $user->id,
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
                    'change' => $amountTendered > 0 ? max(0, $amountTendered - (int) $order->total) : 0,
                    'date' => $order->created_at->toISOString(),
                    'cashier' => $user->name,
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
}
