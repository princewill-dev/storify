<x-email.layout
    title="Order Status Update"
    preheader="Order #{{ $order->order_number }} is now {{ $newStatus }}."
    eyebrow="Order"
    heading="Order status updated"
    subtitle="Order #{{ $order->order_number }}"
    :badge="ucfirst($newStatus)"
    :tone="in_array($newStatus, ['cancelled', 'returned']) ? 'danger' : (in_array($newStatus, ['delivered', 'completed']) ? 'success' : 'info')"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.paragraph>
        Your order status has been updated. Here are the details:
    </x-email.paragraph>

    <x-email.paragraph>
        <x-email.pill :tone="in_array($newStatus, ['cancelled', 'returned']) ? 'danger' : (in_array($newStatus, ['delivered', 'completed']) ? 'success' : 'info')">{{ $newStatus }}</x-email.pill>
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order" value="#{{ $order->order_number }}" strong />
            <x-email.row label="Order date" :value="$order->created_at->format('M d, Y')" />
            <x-email.row label="Total amount" value="₦{{ number_format($order->total, 2) }}" strong />
            <x-email.row label="Payment status" :value="$order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status)" />
            @if($order->delivery_state && $order->delivery_area)
                <x-email.row label="Delivery location" :value="$order->delivery_area.', '.$order->delivery_state" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-collapse:collapse;font-size:13px;line-height:20px;color:#1A1A1A;">
        <thead>
            <tr>
                <th align="left" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Item</th>
                <th align="center" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Qty</th>
                <th align="right" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;">{{ $item->product_name }}</td>
                    <td align="center" style="padding:10px 0;border-bottom:1px solid #F1F5F9;">{{ $item->quantity }}</td>
                    <td align="right" style="padding:10px 0;border-bottom:1px solid #F1F5F9;">₦{{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <x-email.button href="{{ $appUrl }}/orders/{{ $order->id }}">View order details</x-email.button>

    <x-email.paragraph muted>
        If you have any questions about your order, please don't hesitate to contact our support team.
    </x-email.paragraph>
</x-email.layout>
