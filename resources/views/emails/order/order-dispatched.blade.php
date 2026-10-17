<x-email.layout
    title="Order Dispatched"
    preheader="Your order #{{ $order->order_number }} is on its way."
    eyebrow="Order"
    heading="Your order is on its way"
    subtitle="Order #{{ $order->order_number }} has been dispatched"
    badge="Dispatched"
    tone="info"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.paragraph>
        Great news! Your order #{{ $order->order_number }} has left our warehouse and is now on its way to you.
    </x-email.paragraph>

    @if($deliveryRoute)
        <x-email.panel>
            <x-email.rows>
                <x-email.row label="Delivery location" :value="$order->delivery_area.', '.$order->delivery_state" />
                <x-email.row label="Estimated delivery" :value="$deliveryRoute->delivery_days.' days from dispatch'" />
                <x-email.row label="Delivery address" :value="$customer->full_address" :top="true" />
            </x-email.rows>
        </x-email.panel>
    @endif

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Order date" :value="$order->created_at->format('F d, Y')" />
            <x-email.row label="Total amount" value="₦{{ number_format($order->total, 2) }}" strong />
        </x-email.rows>
    </x-email.panel>

    <x-email.callout tone="info" title="What's next?">
        Your order is being delivered to your address. You'll receive another email when it's delivered. Make sure someone is available to receive the package.
    </x-email.callout>

    <x-email.button href="{{ $appUrl }}">Visit our store</x-email.button>
</x-email.layout>
