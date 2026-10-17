<x-email.layout
    title="Payment Confirmed"
    preheader="Payment for order {{ $order->order_number }} has been confirmed."
    eyebrow="Payment"
    heading="Payment confirmed"
    subtitle="{{ $store->name }} · Order {{ $order->order_number }}"
    badge="Paid"
    tone="success"
    :brand="$recipient instanceof \App\Models\Customer ? ['name' => $store->name, 'logo' => $store->logoUrl(), 'email' => $store->support_email, 'phone' => $store->support_phone, 'address' => $store->address] : []"
    :powered-by="$recipient instanceof \App\Models\Customer">

    <x-email.paragraph>Hello <strong>{{ $recipient?->name ?? $recipient->first_name ?? 'Admin' }}</strong>,</x-email.paragraph>

    @if($recipient instanceof \App\Models\Customer)
        <x-email.paragraph>Great news! <strong>{{ $store->name }}</strong> has confirmed your payment for order <strong>{{ $order->order_number }}</strong>.</x-email.paragraph>
    @else
        <x-email.paragraph>A payment has been confirmed for <strong>{{ $store->name }}</strong>. Here are the details:</x-email.paragraph>
    @endif

    <x-email.amount label="Confirmed amount">₦{{ number_format($transaction->amount, 2) }}</x-email.amount>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Transaction reference" :value="$transaction->reference" mono />
            <x-email.row label="Payment method" :value="$transaction->paymentMethod?->name ?? 'Bank Transfer'" />
            <x-email.row label="Confirmed on" :value="now()->format('F d, Y \a\t g:i A')" />
        </x-email.rows>
    </x-email.panel>

    @if($recipient instanceof \App\Models\Customer)
        <x-email.callout tone="success" title="What's next?">
            Your order is now being processed. You'll receive another email once your order has been dispatched.
        </x-email.callout>

        <x-email.button href="{{ \App\Support\SpaUrls::storefront($store->slug, '/track?order='.$order->order_number) }}">Track your order</x-email.button>

        <x-email.paragraph>If you have any questions, please don't hesitate to contact <strong>{{ $store->name }}</strong>.</x-email.paragraph>
        <x-email.paragraph>Thank you for shopping with us!</x-email.paragraph>
    @else
        <x-email.button href="{{ \App\Support\SpaUrls::management('/transactions/'.$transaction->reference) }}">View transaction</x-email.button>
    @endif
</x-email.layout>
