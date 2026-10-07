<?php

namespace App\Services\Management;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WS-13 — the guarded order edit slice (pricing and notes with a server-side
 * total recompute).
 *
 * The order write and its ActivityLog row commit inside one transaction, so
 * a failed audit row cannot leave an untracked price change. The controller
 * keeps the HTTP contract — the guard, the request rules, the message and the
 * response envelope.
 */
final class OrderEditService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data, Request $request): void
    {
        $user = $this->actor($request);

        DB::transaction(function () use ($order, $data, $request, $user) {
            $before = [
                'shipping_fee' => (float) $order->shipping_fee,
                'tax' => (float) $order->tax,
                'total' => (float) $order->total,
            ];

            $shippingFee = round((float) $data['shipping_fee'], 2);
            $tax = round((float) $data['tax'], 2);

            // The edit form only owns shipping, tax and notes, so the total is
            // recomputed here from the persisted pieces — never trusted from
            // the client. Service charge is the one component the form must
            // not touch.
            $total = round(
                (float) $order->subtotal + $shippingFee + $tax + (float) ($order->service_charge_amount ?? 0),
                2,
            );

            $order->update([
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'notes' => $data['notes'] ?? null,
                'total' => $total,
            ]);

            // Legacy changed prices without leaving a trace; the order
            // timeline now records the edit and both totals.
            ActivityLog::create([
                'user_id' => $user->id,
                'business_id' => $order->business_id,
                'action' => 'updated',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'description' => 'Order pricing and notes updated',
                'old_values' => $before,
                'new_values' => [
                    'shipping_fee' => $shippingFee,
                    'tax' => $tax,
                    'total' => $total,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        });
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
