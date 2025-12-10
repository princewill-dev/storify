<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FamilyPackStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentInterval;
use App\Http\Controllers\Controller;
use App\Models\FamilyPackOrder;
use App\Models\FamilyPackItem;
use App\Models\FamilyPackSubscription;
use App\Models\FamilyPackDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AdminFamilyPackController extends Controller
{
    /**
     * List all family pack requests
     */
    public function index(Request $request)
    {
        $query = FamilyPackOrder::with(['customer', 'store'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('pack_type')) {
            $query->where('pack_type', $request->pack_type);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('pack_code', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->paginate(20)->withQueryString();
        $stores = Store::active()->get();

        $stats = [
            'total' => FamilyPackOrder::count(),
            'pending' => FamilyPackOrder::where('status', FamilyPackStatus::PENDING_REVIEW)->count(),
            'active' => FamilyPackOrder::where('status', FamilyPackStatus::ACTIVE)->count(),
            'completed' => FamilyPackOrder::where('status', FamilyPackStatus::COMPLETED)->count(),
        ];

        return view('admin.family_packs.index', compact('orders', 'stores', 'stats'));
    }

    /**
     * Pause an active subscription
     */
    public function pauseSubscription($id)
    {
        $order = FamilyPackOrder::with('subscription')->findOrFail($id);
        if (!$order->subscription || !$order->subscription->is_active) {
            return back()->with('error', 'No active subscription to pause.');
        }
        if ($order->subscription->is_paused) {
            return back()->with('info', 'Subscription already paused.');
        }
        $order->subscription->update([
            'is_paused' => true,
            'paused_at' => now(),
        ]);
        return back()->with('success', 'Subscription paused.');
    }

    /**
     * Resume a paused subscription
     */
    public function resumeSubscription($id)
    {
        $order = FamilyPackOrder::with('subscription')->findOrFail($id);
        if (!$order->subscription || !$order->subscription->is_active) {
            return back()->with('error', 'No active subscription to resume.');
        }
        if (!$order->subscription->is_paused) {
            return back()->with('info', 'Subscription is not paused.');
        }
        $order->subscription->update([
            'is_paused' => false,
            'paused_until' => null,
        ]);
        return back()->with('success', 'Subscription resumed.');
    }

    /**
     * Cancel an active subscription
     */
    public function cancelSubscription($id)
    {
        $order = FamilyPackOrder::with('subscription')->findOrFail($id);
        if (!$order->subscription || !$order->subscription->is_active) {
            return back()->with('error', 'No active subscription to cancel.');
        }
        $order->subscription->update([
            'is_active' => false,
            'cancelled_at' => now(),
            'cancelled_by' => Auth::id(),
        ]);
        return back()->with('success', 'Subscription cancelled.');
    }

    /**
     * Advance to next cycle: create next order, transaction, and mark delivery payment pending
     */
    public function advanceNextCycle($id)
    {
        $familyPackOrder = FamilyPackOrder::with(['subscription', 'deliveryRoute', 'deliveryAddress', 'deliveryInterval', 'items'])
            ->findOrFail($id);

        $subscription = $familyPackOrder->subscription;
        if (!$subscription || !$subscription->is_active) {
            return back()->with('error', 'No active subscription.');
        }
        if ($subscription->is_paused) {
            return back()->with('error', 'Subscription is paused. Resume before advancing.');
        }
        if ($subscription->remaining_cycles <= 0) {
            return back()->with('error', 'No remaining cycles to advance.');
        }

        try {
            DB::beginTransaction();

            // Amounts
            $shippingFee = $familyPackOrder->deliveryRoute ? ($familyPackOrder->deliveryRoute->fee / 100) : 0;
            $vat = Vat::current();
            $vatPercentage = $vat ? $vat->percentage : 0;
            $tax = ($familyPackOrder->subtotal + $shippingFee) * ($vatPercentage / 100);
            $singleDeliveryTotal = $familyPackOrder->subtotal + $shippingFee + $tax;

            $nextCycle = $subscription->current_cycle + 1;

            // Create Order for next cycle
            $order = Order::create([
                'customer_id' => $familyPackOrder->customer_id,
                'store_id' => $familyPackOrder->store_id,
                'subtotal' => $familyPackOrder->subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'total' => $singleDeliveryTotal,
                'status' => OrderStatus::PENDING,
                'payment_status' => 'pending',
                'delivery_address_id' => $familyPackOrder->delivery_address_id,
                'delivery_route_id' => $familyPackOrder->delivery_route_id,
                'delivery_state' => $familyPackOrder->deliveryAddress->state ?? null,
                'delivery_area' => $familyPackOrder->deliveryAddress->area ?? null,
                'delivery_days' => $familyPackOrder->deliveryRoute->delivery_days ?? null,
                'notes' => $familyPackOrder->notes . "\n\nAdmin Notes: " . $familyPackOrder->review_notes,
                'source' => 'family_pack',
            ]);
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

            // Transaction pending
            $transaction = \App\Models\Transaction::create([
                'order_id' => $order->id,
                'amount' => $order->total,
                'status' => \App\Enums\TransactionStatus::PENDING,
            ]);

            // Update/ensure delivery record for this cycle
            $delivery = \App\Models\FamilyPackDelivery::where('family_pack_order_id', $familyPackOrder->id)
                ->where('cycle_number', $nextCycle)
                ->first();
            if (!$delivery) {
                $intervalDays = $familyPackOrder->deliveryInterval ? $familyPackOrder->deliveryInterval->days_count : 30;
                $delivery = \App\Models\FamilyPackDelivery::create([
                    'family_pack_order_id' => $familyPackOrder->id,
                    'cycle_number' => $nextCycle,
                    'scheduled_date' => now()->addDays($intervalDays * ($nextCycle - 1)),
                ]);
            }
            $delivery->update([
                'order_id' => $order->id,
                'status' => \App\Enums\FamilyPackDeliveryStatus::PAYMENT_PENDING,
                'payment_id' => $transaction->id,
            ]);

            // Update subscription counters
            $nextPaymentDate = match ($subscription->payment_interval) {
                PaymentInterval::WEEKLY => now()->addWeek(),
                PaymentInterval::MONTHLY => now()->addMonth(),
                PaymentInterval::SIX_MONTHS => now()->addMonths(6),
                PaymentInterval::TWELVE_MONTHS => now()->addYear(),
            };
            $subscription->update([
                'current_cycle' => $nextCycle,
                'remaining_cycles' => max(0, ($subscription->remaining_cycles - 1)),
                'next_payment_date' => $nextPaymentDate,
            ]);

            DB::commit();

            // Optionally email payment link
            try {
                \Illuminate\Support\Facades\Mail::to($familyPackOrder->customer->email)->queue(new \App\Mail\FamilyPackFinalizedMail($familyPackOrder, $order));
            } catch (\Exception $e) {
                Log::error('family_pack.payment_email_failed', ['error' => $e->getMessage()]);
            }

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Next cycle created. Payment link sent to customer.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('family_pack.advance_cycle_failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to advance cycle.');
        }
    }

    /**
     * View and edit family pack request
     */
    public function show($id)
    {
        $familyPackOrder = FamilyPackOrder::with([
            'customer',
            'store',
            'deliveryAddress',
            'deliveryAddress.deliveryRoute',
            'deliveryRoute',
            'deliveryInterval',
            'items.product',
            'reviewer',
            'subscription',
            'deliveries',
            'deliveries.order',
            'deliveries.payment'
        ])->findOrFail($id);

        // Calculate totals
        $shippingFee = 0;
        if ($familyPackOrder->deliveryRoute) {
            $shippingFee = $familyPackOrder->deliveryRoute->fee / 100;
        }

        $vat = Vat::current();
        $vatPercentage = $vat ? $vat->percentage : 0;
        $tax = ($familyPackOrder->subtotal + $shippingFee) * ($vatPercentage / 100);
        $total = $familyPackOrder->subtotal + $shippingFee + $tax;

        // View helpers to avoid raw PHP logic in Blade
        $showEditHint = in_array($familyPackOrder->status, [FamilyPackStatus::PENDING_REVIEW, FamilyPackStatus::APPROVED]);
        $disableInputs = $familyPackOrder->status === FamilyPackStatus::COMPLETED;
        $canSave = !in_array($familyPackOrder->status, [FamilyPackStatus::COMPLETED, FamilyPackStatus::CANCELLED]);
        $canFinalize = $showEditHint;

        $finalize = null;
        if ($canFinalize) {
            if ($familyPackOrder->pack_type === 'single') {
                $finalize = [
                    'route' => route('admin.family-packs.finalize', $familyPackOrder->id),
                    'label' => 'Convert to Order',
                    'icon' => 'check-circle',
                    'confirm' => 'Are you sure you want to finalize this single order? This will create a regular order.'
                ];
            } else {
                $finalize = [
                    'route' => route('admin.family-packs.activate', $familyPackOrder->id),
                    'label' => 'Activate Subscription',
                    'icon' => 'sync',
                    'confirm' => 'Are you sure you want to activate this subscription? This will generate the delivery schedule.'
                ];
            }
        }

        return view('admin.family_packs.show', compact(
            'familyPackOrder', 'shippingFee', 'tax', 'vatPercentage', 'total',
            'showEditHint', 'disableInputs', 'canSave', 'canFinalize', 'finalize'
        ));
    }

    /**
     * View and edit family pack request by pack code (e.g., PACK-XXXX)
     */
    public function showByCode($packCode)
    {
        $familyPackOrder = FamilyPackOrder::with([
            'customer',
            'store',
            'deliveryAddress',
            'deliveryAddress.deliveryRoute',
            'deliveryRoute',
            'deliveryInterval',
            'items.product',
            'reviewer',
            'subscription',
            'deliveries',
            'deliveries.order',
            'deliveries.payment'
        ])->where('pack_code', $packCode)->firstOrFail();

        // Calculate totals
        $shippingFee = 0;
        if ($familyPackOrder->deliveryRoute) {
            $shippingFee = $familyPackOrder->deliveryRoute->fee / 100;
        }

        $vat = Vat::current();
        $vatPercentage = $vat ? $vat->percentage : 0;
        $tax = ($familyPackOrder->subtotal + $shippingFee) * ($vatPercentage / 100);
        $total = $familyPackOrder->subtotal + $shippingFee + $tax;

        // View helpers to avoid raw PHP logic in Blade
        $showEditHint = in_array($familyPackOrder->status, [FamilyPackStatus::PENDING_REVIEW, FamilyPackStatus::APPROVED]);
        $disableInputs = $familyPackOrder->status === FamilyPackStatus::COMPLETED;
        $canSave = !in_array($familyPackOrder->status, [FamilyPackStatus::COMPLETED, FamilyPackStatus::CANCELLED]);
        $canFinalize = $showEditHint;

        $finalize = null;
        if ($canFinalize) {
            if ($familyPackOrder->pack_type === 'single') {
                $finalize = [
                    'route' => route('admin.family-packs.finalize', $familyPackOrder->id),
                    'label' => 'Convert to Order',
                    'icon' => 'check-circle',
                    'confirm' => 'Are you sure you want to finalize this single order? This will create a regular order.'
                ];
            } else {
                $finalize = [
                    'route' => route('admin.family-packs.activate', $familyPackOrder->id),
                    'label' => 'Activate Subscription',
                    'icon' => 'sync',
                    'confirm' => 'Are you sure you want to activate this subscription? This will generate the delivery schedule.'
                ];
            }
        }

        return view('admin.family_packs.show', compact(
            'familyPackOrder', 'shippingFee', 'tax', 'vatPercentage', 'total',
            'showEditHint', 'disableInputs', 'canSave', 'canFinalize', 'finalize'
        ));
    }

    /**
     * Update family pack pricing and items
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'review_notes' => 'nullable|string|max:2000',
            'items' => 'required|array',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.accepted_amount' => 'nullable|numeric|min:0',
        ]);

        $familyPackOrder = FamilyPackOrder::findOrFail($id);

        try {
            DB::beginTransaction();

            // Update admin notes
            $familyPackOrder->update([
                'review_notes' => $request->review_notes,
                'reviewed_at' => now(),
                'reviewed_by' => Auth::id(),
                'last_updated_by' => 'admin',
                'status' => FamilyPackStatus::APPROVED,
            ]);

            // Update items
            $subtotal = 0;
            foreach ($request->items as $itemId => $itemData) {
                $item = FamilyPackItem::where('id', $itemId)
                    ->where('family_pack_order_id', $familyPackOrder->id)
                    ->firstOrFail();

                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'] ?? null;
                $accepted = $itemData['accepted_amount'] ?? null;

                if ($item->is_custom && $accepted !== null && $accepted !== '') {
                    $unitPrice = $quantity > 0 ? round(((float)$accepted) / $quantity, 2) : 0;
                    $computedSubtotal = (float)$accepted;
                } else {
                    $computedSubtotal = $unitPrice ? ($unitPrice * $quantity) : 0;
                }

                $item->update([
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $computedSubtotal,
                ]);

                $subtotal += $item->subtotal;
            }

            // Update order subtotal and estimated total
            $shippingFee = $familyPackOrder->deliveryRoute ? ($familyPackOrder->deliveryRoute->fee / 100) : 0;
            $vat = Vat::current();
            $vatPercentage = $vat ? $vat->percentage : 0;
            $tax = ($subtotal + $shippingFee) * ($vatPercentage / 100);
            $estimatedTotal = $subtotal + $shippingFee + $tax;

            $familyPackOrder->update([
                'subtotal' => $subtotal,
                'estimated_total' => $estimatedTotal,
            ]);

            DB::commit();

            Log::info('Family pack order updated by admin', [
                'pack_code' => $familyPackOrder->pack_code,
                'admin_id' => Auth::id(),
            ]);

            // Send email notification
            try {
                \Illuminate\Support\Facades\Mail::to($familyPackOrder->customer->email)->queue(new \App\Mail\FamilyPackUpdated($familyPackOrder));
            } catch (\Exception $e) {
                Log::error('Failed to send family pack update email', ['error' => $e->getMessage()]);
            }

            return back()->with('success', 'Family pack order updated successfully!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update family pack order', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to update order. Please try again.');
        }
    }

    /**
     * Finalize single purchase (convert to regular order)
     */
    public function finalize($id)
    {
        $familyPackOrder = FamilyPackOrder::with([
            'items',
            'deliveryRoute',
            'deliveryAddress'
        ])->findOrFail($id);

        if ($familyPackOrder->pack_type !== 'single') {
            return back()->with('error', 'Only single purchase orders can be finalized this way.');
        }

        if ($familyPackOrder->status === FamilyPackStatus::COMPLETED) {
            return back()->with('error', 'Order is already completed.');
        }

        try {
            DB::beginTransaction();

            // Calculate totals
            $shippingFee = $familyPackOrder->deliveryRoute ? ($familyPackOrder->deliveryRoute->fee / 100) : 0;
            $vat = Vat::current();
            $vatPercentage = $vat ? $vat->percentage : 0;
            $tax = ($familyPackOrder->subtotal + $shippingFee) * ($vatPercentage / 100);
            $total = $familyPackOrder->subtotal + $shippingFee + $tax;

            // Create regular Order
            $order = Order::create([
                'customer_id' => $familyPackOrder->customer_id,
                'store_id' => $familyPackOrder->store_id,
                'subtotal' => $familyPackOrder->subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'total' => $total,
                'status' => OrderStatus::PENDING,
                'payment_status' => 'pending',
                'delivery_address_id' => $familyPackOrder->delivery_address_id,
                'delivery_route_id' => $familyPackOrder->delivery_route_id,
                'delivery_state' => $familyPackOrder->deliveryAddress->state ?? null,
                'delivery_area' => $familyPackOrder->deliveryAddress->area ?? null,
                'delivery_days' => $familyPackOrder->deliveryRoute->delivery_days ?? null,
                'notes' => $familyPackOrder->notes . "\n\nAdmin Notes: " . $familyPackOrder->review_notes,
                'source' => 'family_pack',
            ]);

            // Create order items
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

            // Update family pack order
            $familyPackOrder->update([
                'first_order_id' => $order->id,
                'status' => FamilyPackStatus::COMPLETED,
            ]);

            DB::commit();

            Log::info('Family pack single order finalized', [
                'pack_code' => $familyPackOrder->pack_code,
                'order_number' => $order->order_number,
            ]);

            // TODO: Send email notification

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Family pack finalized! Regular order created: #' . $order->order_number);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to finalize family pack order', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to finalize order. Please try again.');
        }
    }

    /**
     * Activate recurring subscription
     */
    public function activate($id)
    {
        $familyPackOrder = FamilyPackOrder::with([
            'items',
            'deliveryRoute',
            'deliveryAddress',
            'deliveryInterval'
        ])->findOrFail($id);

        if ($familyPackOrder->pack_type !== 'recurring') {
            return back()->with('error', 'Only recurring orders can be activated as subscriptions.');
        }

        if ($familyPackOrder->status === FamilyPackStatus::ACTIVE) {
            return back()->with('error', 'Subscription is already active.');
        }

        try {
            DB::beginTransaction();

            // Calculate amounts for a single delivery
            $shippingFee = $familyPackOrder->deliveryRoute ? ($familyPackOrder->deliveryRoute->fee / 100) : 0;
            $vat = Vat::current();
            $vatPercentage = $vat ? $vat->percentage : 0;
            $tax = ($familyPackOrder->subtotal + $shippingFee) * ($vatPercentage / 100);
            $singleDeliveryTotal = $familyPackOrder->subtotal + $shippingFee + $tax;

            $paymentInterval = PaymentInterval::from($familyPackOrder->payment_interval);
            $cycles = $paymentInterval->cycles();

            // Create regular Order for the first batch immediately
            $order = Order::create([
                'customer_id' => $familyPackOrder->customer_id,
                'store_id' => $familyPackOrder->store_id,
                'subtotal' => $familyPackOrder->subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $tax,
                'total' => $singleDeliveryTotal,
                'status' => OrderStatus::PENDING,
                'payment_status' => 'pending',
                'delivery_address_id' => $familyPackOrder->delivery_address_id,
                'delivery_route_id' => $familyPackOrder->delivery_route_id,
                'delivery_state' => $familyPackOrder->deliveryAddress->state ?? null,
                'delivery_area' => $familyPackOrder->deliveryAddress->area ?? null,
                'delivery_days' => $familyPackOrder->deliveryRoute->delivery_days ?? null,
                'notes' => $familyPackOrder->notes . "\n\nAdmin Notes: " . $familyPackOrder->review_notes,
                'source' => 'family_pack',
            ]);

            // Create order items
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

            // Create subscription (interval_amount equals one cycle amount). Next payment date based on interval.
            $nextPaymentDate = match ($paymentInterval) {
                PaymentInterval::WEEKLY => now()->addWeek(),
                PaymentInterval::MONTHLY => now()->addMonth(),
                PaymentInterval::SIX_MONTHS => now()->addMonths(6),
                PaymentInterval::TWELVE_MONTHS => now()->addYear(),
            };

            $subscription = FamilyPackSubscription::create([
                'family_pack_order_id' => $familyPackOrder->id,
                'customer_id' => $familyPackOrder->customer_id,
                'payment_interval' => $familyPackOrder->payment_interval,
                'interval_amount' => $singleDeliveryTotal,
                'total_cycles' => $cycles,
                'current_cycle' => 1, // first cycle just created
                'remaining_cycles' => max(0, $cycles - 1),
                'next_payment_date' => $nextPaymentDate,
                'is_active' => true,
            ]);

            // Create pending transaction for this first order
            $transaction = \App\Models\Transaction::create([
                'order_id' => $order->id,
                'amount' => $order->total,
                'status' => \App\Enums\TransactionStatus::PENDING,
            ]);

            // Generate delivery schedule and link cycle 1 to the created order/transaction
            $deliveryInterval = $familyPackOrder->deliveryInterval;
            $intervalDays = $deliveryInterval ? $deliveryInterval->days_count : 30; // Default monthly

            for ($i = 1; $i <= $cycles; $i++) {
                $scheduledDate = $i === 1 ? now() : now()->addDays($intervalDays * ($i - 1));

                FamilyPackDelivery::create([
                    'family_pack_order_id' => $familyPackOrder->id,
                    'order_id' => $i === 1 ? $order->id : null,
                    'cycle_number' => $i,
                    'scheduled_date' => $scheduledDate,
                    'status' => $i === 1 ? \App\Enums\FamilyPackDeliveryStatus::PAYMENT_PENDING : \App\Enums\FamilyPackDeliveryStatus::PENDING,
                    'payment_id' => $i === 1 ? $transaction->id : null,
                ]);
            }

            // Update family pack order
            $familyPackOrder->update([
                'status' => FamilyPackStatus::ACTIVE,
                'first_order_id' => $order->id,
            ]);

            DB::commit();

            Log::info('family_pack.activated_and_first_order_created', [
                'pack_code' => $familyPackOrder->pack_code,
                'subscription_id' => $subscription->id,
                'total_cycles' => $cycles,
                'order_number' => $order->order_number,
            ]);

            // Send payment email (queued)
            try {
                \Illuminate\Support\Facades\Mail::to($familyPackOrder->customer->email)->queue(new \App\Mail\FamilyPackFinalizedMail($familyPackOrder, $order));
            } catch (\Exception $e) {
                Log::error('family_pack.payment_email_failed', ['error' => $e->getMessage()]);
            }

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Subscription activated. First batch created and payment link sent to customer.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to activate family pack subscription', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to activate subscription. Please try again.');
        }
    }
}
