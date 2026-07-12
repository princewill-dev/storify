<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status Update</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f4f4; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 8px 0 0; opacity: 0.9; font-size: 14px; }
        .content { background: #ffffff; padding: 30px 24px; }
        .status-box { background: #eff6ff; border: 1px solid #bfdbfe; padding: 15px; margin: 20px 0; border-radius: 8px; text-align: center; }
        .status-badge { display: inline-block; padding: 6px 16px; border-radius: 50px; font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; }
        .status-badge.accepted { background: #dbeafe; color: #1d4ed8; }
        .status-badge.processing { background: #e0e7ff; color: #4338ca; }
        .status-badge.dispatched { background: #fef3c7; color: #92400e; }
        .status-badge.delivered { background: #d1fae5; color: #065f46; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
        .status-badge.returned { background: #fee2e2; color: #991b1b; }
        .order-details { background: #f9fafb; padding: 20px; margin: 20px 0; border-radius: 8px; border: 1px solid #e5e7eb; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; color: #6b7280; font-size: 13px; }
        .detail-value { color: #111827; font-size: 14px; font-weight: 500; }
        .button { display: inline-block; padding: 12px 28px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin: 10px 0; font-weight: 600; font-size: 14px; }
        .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 12px; background: #f9fafb; border-radius: 0 0 8px 8px; border-top: 1px solid #e5e7eb; }
        .note { background: #f0f9ff; border-left: 4px solid #2563eb; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Order Status Updated</h1>
            <p>Your order #{{ $order->order_number }} has been updated</p>
        </div>

        <div class="content">
            <p>Hello{{ $customer?->first_name ? ' ' . $customer->first_name : '' }},</p>

            <p>Your order status has changed:</p>

            <div class="status-box">
                <span class="status-badge {{ $newStatus }}">{{ ucfirst($newStatus) }}</span>
            </div>

            <div class="order-details">
                <h3 style="margin-top: 0; color: #2563eb; font-size: 16px;">Order Details</h3>

                <div class="detail-row">
                    <span class="detail-label">Order Number</span>
                    <span class="detail-value">{{ $order->order_number }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Total Amount</span>
                    <span class="detail-value" style="font-weight: 700;">₦{{ number_format($order->total, 2) }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Items</span>
                    <span class="detail-value">{{ $order->items->count() }} item(s)</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Updated On</span>
                    <span class="detail-value">{{ now()->format('F d, Y \a\t g:i A') }}</span>
                </div>
            </div>

            @if($newStatus === 'dispatched')
                <div class="note">
                    <strong>Your order is on the way!</strong><br>
                    Your items have been dispatched and are being delivered. Track your order for real-time updates.
                </div>
            @elseif($newStatus === 'delivered')
                <div class="note">
                    <strong>Order delivered!</strong><br>
                    We hope you love your purchase. If you have any issues, please contact support.
                </div>
            @elseif($newStatus === 'cancelled')
                <div class="note">
                    <strong>Order cancelled.</strong><br>
                    If you didn't request this cancellation, please contact support immediately.
                </div>
            @else
                <div class="note">
                    <strong>What's Next?</strong><br>
                    We'll keep you updated as your order progresses. You can track your order anytime.
                </div>
            @endif

            <div style="text-align: center;">
                @php
                    $store = $order->store;
                    $trackUrl = $store ? route('home.store.order.track', ['store_subdomain' => $store->slug, 'orderNumber' => $order->order_number]) : config('app.url');
                @endphp
                <a href="{{ $trackUrl }}" class="button">Track Your Order</a>
            </div>

            <p style="margin-top: 30px;">If you have any questions, please contact the store directly.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'Storify') }}. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this message.</p>
        </div>
    </div>
</body>
</html>
