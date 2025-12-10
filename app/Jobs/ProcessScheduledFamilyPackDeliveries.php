<?php

namespace App\Jobs;

use App\Enums\FamilyPackStatus;
use App\Enums\OrderStatus;
use App\Models\FamilyPackDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessScheduledFamilyPackDeliveries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $today = now()->startOfDay();

        // Find pending deliveries scheduled for today or earlier
        $deliveries = FamilyPackDelivery::where('status', 'pending')
            ->whereDate('scheduled_date', '<=', $today)
            ->with(['familyPackOrder.items', 'familyPackOrder.deliveryAddress', 'familyPackOrder.deliveryRoute', 'familyPackOrder.subscription'])
            ->get();

        foreach ($deliveries as $delivery) {
            $familyPackOrder = $delivery->familyPackOrder;

            // Skip if subscription is not active or paused
            if ($familyPackOrder->subscription && (!$familyPackOrder->subscription->is_active || $familyPackOrder->subscription->is_paused)) {
                Log::info("Skipping delivery #{$delivery->id} for pack {$familyPackOrder->pack_code}: Subscription inactive or paused.");
                continue;
            }

            // Skip if family pack order itself is not active
            if ($familyPackOrder->status !== FamilyPackStatus::ACTIVE) {
                Log::info("Skipping delivery #{$delivery->id}: Family Pack Order is not active.");
                continue;
            }

            try {
                DB::beginTransaction();

                // Calculate totals (re-calculate to ensure accuracy)
                $subtotal = $familyPackOrder->subtotal;
                $shippingFee = $familyPackOrder->deliveryRoute ? ($familyPackOrder->deliveryRoute->fee / 100) : 0;
                $vat = Vat::current();
                $vatPercentage = $vat ? $vat->percentage : 0;
                $tax = ($subtotal + $shippingFee) * ($vatPercentage / 100);
                $total = $subtotal + $shippingFee + $tax;

                // Create Order
                $order = Order::create([
                    'customer_id' => $familyPackOrder->customer_id,
                    'store_id' => $familyPackOrder->store_id,
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingFee,
                    'tax' => $tax,
                    'total' => $total,
                    'status' => OrderStatus::PENDING, // Or PROCESSING since it's part of a pack
                    'payment_status' => 'paid', // Assuming subscription covers it. Adjust logic if per-delivery payment needed.
                    'delivery_address_id' => $familyPackOrder->delivery_address_id,
                    'delivery_route_id' => $familyPackOrder->delivery_route_id,
                    'delivery_state' => $familyPackOrder->deliveryAddress->state ?? null,
                    'delivery_area' => $familyPackOrder->deliveryAddress->area ?? null,
                    'delivery_days' => $familyPackOrder->deliveryRoute->delivery_days ?? null,
                    'notes' => "Family Pack Delivery Cycle #{$delivery->cycle_number}\n" . $familyPackOrder->notes,
                    'source' => 'family_pack',
                ]);

                // Create Order Items
                foreach ($familyPackOrder->items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'product_code' => $item->product_code,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price ?? 0,
                        'subtotal' => $item->subtotal,
                    ]);
                }

                // Update Delivery Record
                $delivery->update([
                    'status' => 'processing',
                    'order_id' => $order->id,
                ]);

                DB::commit();

                Log::info("Generated Order #{$order->order_number} for Family Pack Delivery #{$delivery->id}");

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Failed to process delivery #{$delivery->id}: " . $e->getMessage());
            }
        }
    }
}
