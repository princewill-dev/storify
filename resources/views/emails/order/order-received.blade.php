<x-email.layout
    title="Order Confirmation"
    preheader="We received your order #{{ $order->order_number }}."
    eyebrow="Order"
    heading="Order confirmed"
    subtitle="Thank you for your order"
    badge="Received"
    tone="info"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.paragraph>
        Your order has been received and is being processed. We'll send you another email when your order has been dispatched.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" value="#{{ $order->order_number }}" strong />
            <x-email.row label="Order date" :value="$order->created_at->format('F d, Y')" />
            <x-email.row label="Delivery address" :value="$customer->full_address" :top="true" />
            @if($order->delivery_state && $order->delivery_area)
                <x-email.row label="Delivery location" :value="$order->delivery_area.', '.$order->delivery_state" />
            @endif
            @if($order->deliveryRoute)
                <x-email.row label="Estimated delivery" :value="$order->deliveryRoute->delivery_days.' days'" />
            @endif
            <x-email.row label="Payment status" :value="$order->payment_status instanceof \App\Enums\PaymentStatus ? $order->payment_status->value : ucfirst($order->payment_status)" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Order items</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            @foreach($items as $item)
                <x-email.row :label="$item->product_name.' ('.$item->product_code.')'" :value="$item->quantity.' × ₦'.number_format($item->unit_price, 2).' = ₦'.number_format($item->subtotal, 2)" :top="true" />
            @endforeach
        </x-email.rows>
    </x-email.panel>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Subtotal" value="₦{{ number_format($subtotal ?? $order->subtotal, 2) }}" />
            <x-email.row label="Shipping" value="₦{{ number_format($order->shipping_fee, 2) }}" />
            <x-email.row label="Tax" value="₦{{ number_format($order->tax, 2) }}" />
            <x-email.row label="Total" value="₦{{ number_format(($subtotal ?? $order->subtotal) + $order->shipping_fee + $order->tax, 2) }}" strong />
        </x-email.rows>
    </x-email.panel>

    @if($order->notes)
        <x-email.callout tone="neutral" title="Order notes">
            {{ $order->notes }}
        </x-email.callout>
    @endif

    <x-email.button href="{{ $appUrl }}">Visit our store</x-email.button>
</x-email.layout>
