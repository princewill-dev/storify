<x-email.layout
    title="Payment Received"
    preheader="Your POS receipt for order #{{ $order->order_number ?? $order->id }}."
    eyebrow="Receipt"
    heading="Payment received"
    subtitle="Thank you for your purchase"
    badge="Paid"
    tone="success"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.details>
        <x-email.detail label="Order" value="#{{ $order->order_number ?? $order->id }}" />
        <x-email.detail label="Store" :value="$order->store?->name ?? 'Store'" />
        <x-email.detail label="Date" :value="$order->created_at->format('M d, Y h:i A')" />
    </x-email.details>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-collapse:collapse;font-size:13px;line-height:20px;color:#475569;">
        <thead>
            <tr>
                <th align="left" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Item</th>
                <th align="center" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Qty</th>
                <th align="right" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Price</th>
                <th align="right" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid #F1F5F9;">{{ $item->product_name }}</td>
                    <td align="center" style="padding:10px 0;border-bottom:1px solid #F1F5F9;">{{ $item->quantity }}</td>
                    <td align="right" style="padding:10px 0;border-bottom:1px solid #F1F5F9;">₦{{ number_format($item->unit_price, 2) }}</td>
                    <td align="right" style="padding:10px 0;font-weight:600;color:#0B0B0E;border-bottom:1px solid #F1F5F9;">₦{{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Total" value="₦{{ number_format($order->total, 2) }}" strong />
            @if($order->meta['payment_method'] ?? false)
                <x-email.row label="Paid via" :value="ucfirst($order->meta['payment_method'])" />
            @endif
        </x-email.rows>
    </x-email.panel>
</x-email.layout>
