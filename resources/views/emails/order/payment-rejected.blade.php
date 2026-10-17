<x-email.layout
    title="Payment Issue"
    preheader="A payment for order {{ $order->order_number }} could not be confirmed."
    eyebrow="Payment"
    heading="Payment issue"
    subtitle="{{ $store->name }} · Order {{ $order->order_number }}"
    badge="Rejected"
    tone="danger"
    :brand="$recipient instanceof \App\Models\Customer ? ['name' => $store->name, 'logo' => $store->logoUrl(), 'email' => $store->support_email, 'phone' => $store->support_phone, 'address' => $store->address] : []"
    :powered-by="$recipient instanceof \App\Models\Customer">

    <x-email.paragraph>Hello <strong>{{ $recipient?->name ?? $recipient->first_name ?? 'Admin' }}</strong>,</x-email.paragraph>

    @if($recipient instanceof \App\Models\Customer)
        <x-email.paragraph>We regret to inform you that <strong>{{ $store->name }}</strong> could not confirm your payment for order <strong>{{ $order->order_number }}</strong>.</x-email.paragraph>
    @else
        <x-email.paragraph>A payment for <strong>{{ $store->name }}</strong> has been rejected. Here are the details:</x-email.paragraph>
    @endif

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Transaction reference" :value="$transaction->reference" mono />
            <x-email.row label="Payment method" :value="$transaction->paymentMethod?->name ?? 'Bank Transfer'" />
            <x-email.row label="Amount" :value="'₦'.number_format($transaction->amount, 2)" strong tone="danger" />
            <x-email.row label="Rejected on" :value="now()->format('F d, Y \a\t g:i A')" />
        </x-email.rows>
    </x-email.panel>

    @if(!empty($reason))
        <x-email.callout tone="danger" title="Reason">
            {{ $reason }}
        </x-email.callout>
    @endif

    @if($recipient instanceof \App\Models\Customer)
        <x-email.callout tone="warning" title="What should you do?">
            Please contact the store's support team or attempt to resubmit your payment with the correct amount and payment proof. We're here to help resolve this quickly.
        </x-email.callout>

        <x-email.button href="{{ \App\Support\SpaUrls::storefront($store->slug, '/track?order='.$order->order_number) }}">View order</x-email.button>

        @if($store->support_email)
            <x-email.paragraph muted>
                You can also reach the store at <a href="mailto:{{ $store->support_email }}" style="color:#2563EB;text-decoration:none;">{{ $store->support_email }}</a>.
            </x-email.paragraph>
        @endif

        <x-email.paragraph>We apologize for any inconvenience this may have caused.</x-email.paragraph>
    @else
        <x-email.button href="{{ \App\Support\SpaUrls::management('/transactions/'.$transaction->reference) }}">View transaction</x-email.button>
    @endif
</x-email.layout>
