<x-email.layout
    title="New Order Notification"
    preheader="A new order #{{ $order->order_number }} needs your attention."
    eyebrow="Order"
    heading="New order received"
    subtitle="Order #{{ $order->order_number }}"
    badge="Action required"
    tone="warning">

    <x-email.callout tone="warning" title="Action required">
        A new order has been placed and requires your attention.
    </x-email.callout>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Order date" :value="$order->created_at->format('F d, Y H:i:s')" />
            <x-email.row label="Order total" value="₦{{ number_format($order->total, 2) }}" strong />
            <x-email.row label="Payment status" :value="$order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status)" />
            <x-email.row label="Order status" :value="ucfirst($order->status->value ?? $order->status)" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Store information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Store" :value="$store->name" strong />
            <x-email.row label="Store slug" :value="$store->slug" mono />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Customer information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Name" :value="$customer->full_name" />
            <x-email.row label="Email" :value="$customer->email" />
            <x-email.row label="Phone" :value="$customer->phone" />
            <x-email.row label="Address" :value="$customer->full_address" :top="true" />
            @if($order->delivery_state && $order->delivery_area)
                <x-email.row label="Delivery location" :value="$order->delivery_area.', '.$order->delivery_state" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Order items ({{ $items->count() }} items)</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            @foreach($items as $item)
                <x-email.row :label="$item->product_name.' ('.$item->product_code.')'" :value="$item->quantity.' × ₦'.number_format($item->unit_price, 2).' = ₦'.number_format($item->subtotal, 2)" :top="true" />
            @endforeach
        </x-email.rows>
    </x-email.panel>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Subtotal" value="₦{{ number_format($order->subtotal, 2) }}" />
            <x-email.row label="Shipping" value="₦{{ number_format($order->shipping_fee, 2) }}" />
            <x-email.row label="Tax" value="₦{{ number_format($order->tax, 2) }}" />
            <x-email.row label="Total" value="₦{{ number_format($order->total, 2) }}" strong />
        </x-email.rows>
    </x-email.panel>

    @if($order->notes)
        <x-email.callout tone="neutral" title="Customer notes">
            {{ $order->notes }}
        </x-email.callout>
    @endif

    <x-email.button href="{{ $adminUrl }}">View order in admin panel</x-email.button>
</x-email.layout>
