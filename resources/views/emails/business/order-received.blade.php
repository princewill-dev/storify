<x-email.layout
    title="New Order Received"
    preheader="Order #{{ $order->order_number }} is a new paid order for {{ $store->name }}."
    eyebrow="Order"
    heading="Order #{{ $order->order_number }} received"
    subtitle="{{ $store->name }} now has a new paid order."
    badge="Paid"
    tone="success"
    :brand="$store ? ['name' => $store->name, 'logo' => $store->logoUrl(), 'email' => $store->support_email, 'phone' => $store->support_phone, 'address' => $store->address] : []"
    :powered-by="(bool) $store">

    <x-email.heading>Order summary</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order total" value="₦{{ number_format($order->total, 2) }}" strong />
            <x-email.row label="Payment status" :value="$order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status)" />
            <x-email.row label="Delivery" :value="$order->delivery_area.' · '.($order->delivery_days ?? '—').' days'" />
            <x-email.row label="Shipping fee" value="₦{{ number_format($order->shipping_fee, 2) }}" />
        </x-email.rows>
    </x-email.panel>

    <x-email.panel>
        <x-email.rows>
            @foreach($items as $item)
                <x-email.row :label="$item->product_name" :value="$item->quantity.' × ₦'.number_format($item->unit_price, 2).' · ₦'.number_format($item->subtotal, 2)" :top="true" />
            @endforeach
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Customer</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Name" :value="$customer->full_name" />
            <x-email.row label="Email" :value="$customer->email" />
            <x-email.row label="Phone" :value="$customer->recipient_phone ?? $customer->phone ?? '—'" />
            <x-email.row label="Address" :value="$order->delivery_area.', '.$order->delivery_state" :top="true" />
            @if($order->notes)
                <x-email.row label="Notes" :value="$order->notes" :top="true" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/orders/'.$order->order_number) }}">View order in dashboard</x-email.button>
</x-email.layout>
