<x-email.layout
    title="Payment received"
    preheader="A new payment of {{ $amount }} has landed on your store."
    eyebrow="Payment"
    heading="Payment received"
    badge="Credited"
    tone="success">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        A new payment has been made on your store. Here is the information. Please check your dashboard for more info.
    </x-email.paragraph>

    <x-email.panel>
        <div style="margin:0 0 4px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Payment details</div>
        <x-email.amount>{{ $amount }}</x-email.amount>
        <x-email.rows>
            @if($fee)
                <x-email.row label="Processing fee" :value="$fee" />
            @endif
            @if($storeName)
                <x-email.row label="Store" :value="$storeName" />
            @endif
            @if($reference)
                <x-email.row label="Reference" :value="$reference" mono />
            @endif
            @if($date)
                <x-email.row label="Date" :value="$date" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph muted>
        Please check your dashboard for more info about this payment.
    </x-email.paragraph>
</x-email.layout>
