<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Enums\OrderStatus;

class TrackingController extends Controller
{
    public function index(Request $request)
    {
        $prefill = trim((string) $request->get('order')) ?: null;
        $error = null;

        if ($prefill) {
            $order = Order::where('order_number', $prefill)->first();

            if ($order) {
                return redirect()->route('tracking.show', $order);
            }

            $error = 'We could not find an order with that tracking number. Please check and try again.';
        }

        return view('home.pages.tracking.index', [
            'prefillReference' => $prefill,
            'error' => $error,
        ]);
    }

    public function show(Request $request, Order $order)
    {
        $order->load([
            'customer',
            'store',
            'vendor',
            'items.product',
            'transactions.paymentMethod',
            'deliveryAddress.deliveryRoute',
            'deliveryRoute',
        ]);

        $timeline = ActivityLog::with('user')
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($timeline->isEmpty()) {
            $timeline = collect([
                (object) [
                    'created_at' => $order->created_at,
                    'description' => 'Order created',
                    'user' => null,
                ],
            ]);
        }

        $statusMap = [
            'pending' => 'Pending Confirmation',
            'accepted' => 'Accepted',
            'processing' => 'Being Prepared',
            'dispatched' => 'On the Way',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'returned' => 'Returned',
        ];

        $currentIndex = $this->statusProgressIndex($order->status);

        $milestones = collect(array_keys($statusMap))
            ->map(function ($status, $index) use ($statusMap, $currentIndex) {
                $milestoneIndex = $this->statusProgressIndex($status);

                return [
                    'status' => $status,
                    'label' => $statusMap[$status] ?? ucfirst($status),
                    'reached' => $milestoneIndex !== PHP_INT_MAX && $milestoneIndex <= $currentIndex,
                    'is_current' => $milestoneIndex !== PHP_INT_MAX && $milestoneIndex === $currentIndex,
                    'position' => $index,
                ];
            });

        return view('home.pages.tracking.show', [
            'order' => $order,
            'timeline' => $timeline,
            'milestones' => $milestones,
        ]);
    }

    private function statusProgressIndex(OrderStatus|string $status): int
    {
        // Convert enum to string value if needed
        $statusValue = $status instanceof OrderStatus ? $status->value : $status;
        
        $sequence = [
            'pending',
            'accepted',
            'processing',
            'dispatched',
            'delivered',
            'completed',
            'cancelled',
            'returned',
        ];

        $index = array_search($statusValue, $sequence, true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
