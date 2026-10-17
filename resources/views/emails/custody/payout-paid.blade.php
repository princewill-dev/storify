<x-email.layout
    title="Payout sent"
    preheader="A payout for {{ $business->name }} has been sent to your bank."
    eyebrow="Payout"
    heading="Payout sent"
    subtitle="{{ $business->name }}"
    badge="Sent"
    tone="success">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        We have sent a payout for <strong>{{ $business->name }}</strong>. The money is on its way to your bank.
    </x-email.paragraph>

    <x-email.panel>
        <div style="margin:0 0 4px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Amount sent</div>
        <x-email.amount label="Sent {{ $paidAt }}">{{ $payout->currency ?: config('custody.currency', 'NGN') }} {{ $amount }}</x-email.amount>
    </x-email.panel>

    <x-email.heading>Paid to</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Bank" :value="($bankName ?? 'Your bank account').($bankCode ? ' ('.$bankCode.')' : '')" />
            <x-email.row label="Account number" :value="$accountNumberMasked" mono />
            @if($accountName)
                <x-email.row label="Account name" :value="$accountName" />
            @endif
            @if($sales > 0)
                <x-email.row label="Sales covered" :value="$sales" />
            @endif
            @if($reference)
                <x-email.row label="Reference" :value="$reference" mono />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph>
        How quickly it appears is up to your bank. Most transfers land within minutes; some banks hold incoming credits until the next working day. Quote the reference above if you need to ask them about it.
    </x-email.paragraph>
</x-email.layout>
