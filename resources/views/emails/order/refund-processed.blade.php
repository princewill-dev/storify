<x-email.layout
    title="Refund Processed"
    preheader="Your refund for order {{ $order->order_number }} has been initiated."
    eyebrow="Refund"
    heading="Refund processed"
    subtitle="{{ $store->name }} · Order {{ $order->order_number }}"
    badge="Refunded"
    tone="info"
    :brand="['name' => $store->name, 'logo' => $store->logoUrl(), 'email' => $store->support_email, 'phone' => $store->support_phone, 'address' => $store->address]"
    :powered-by="true">

    <x-email.paragraph>Hello <strong>{{ $customer->first_name }}</strong>,</x-email.paragraph>

    <x-email.paragraph>We're writing to confirm that <strong>{{ $store->name }}</strong> has processed a refund for your order <strong>{{ $order->order_number }}</strong>.</x-email.paragraph>

    <x-email.amount label="Refund amount">₦{{ number_format($transaction->amount, 2) }}</x-email.amount>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Transaction ID" :value="$transaction->reference" mono />
            <x-email.row label="Payment method" :value="$transaction->paymentMethod?->name ?? 'N/A'" />
            <x-email.row label="Refund initiated on" :value="now()->format('F d, Y \a\t g:i A')" />
        </x-email.rows>
    </x-email.panel>

    <x-email.callout tone="warning" title="Refund reason">
        {{ $reason }}
    </x-email.callout>

    <x-email.callout tone="info" title="What happens next?">
        Your refund will be processed within 5-10 business days. The amount will be credited back to your original payment method, in the same account or card you used for payment. If you have any questions, please contact our support team.
    </x-email.callout>

    <x-email.button href="{{ config('app.url') }}">View order</x-email.button>

    @if($store->support_email)
        <x-email.paragraph muted>
            For support, email us at <a href="mailto:{{ $store->support_email }}" style="color:#2563EB;text-decoration:none;">{{ $store->support_email }}</a>.
        </x-email.paragraph>
    @endif

    <x-email.paragraph>We apologize for any inconvenience. If you have any questions about this refund, please don't hesitate to reach out.</x-email.paragraph>
</x-email.layout>
