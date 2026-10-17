<x-email.layout
    title="Order Delivered"
    preheader="Order #{{ $order->order_number }} has been delivered."
    eyebrow="Order"
    heading="Order delivered"
    subtitle="Order #{{ $order->order_number }}"
    badge="Delivered"
    tone="success"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.paragraph>
        We're happy to confirm that your order has been successfully delivered to your address.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Order date" :value="$order->created_at->format('F d, Y')" />
            <x-email.row label="Delivery date" :value="now()->format('F d, Y')" />
            <x-email.row label="Total amount" value="₦{{ number_format($order->total, 2) }}" strong />
        </x-email.rows>
    </x-email.panel>

    <x-email.callout tone="success" title="How was your experience?">
        We'd love to hear your feedback about your purchase and delivery experience.
    </x-email.callout>

    <x-email.button href="{{ $appUrl }}">Share your feedback</x-email.button>

    <x-email.heading>Need help?</x-email.heading>

    <x-email.paragraph>
        If you have any issues with your order or need assistance, please don't hesitate to contact our support team.
    </x-email.paragraph>

    <x-email.button href="{{ $appUrl }}">Continue shopping</x-email.button>
</x-email.layout>
