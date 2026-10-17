<x-email.layout
    title="Payout could not be sent"
    preheader="A payout for {{ $business->name }} did not go through. Your money is safe."
    eyebrow="Payout"
    heading="Payout could not be sent"
    subtitle="{{ $business->name }}"
    badge="Failed"
    tone="danger">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.callout tone="warning" title="Nothing has been lost.">
        The money is safe and we will try again automatically.
    </x-email.callout>

    <x-email.paragraph>
        We tried to send a payout for <strong>{{ $business->name }}</strong> and your bank refused the transfer, so it did not go through.
    </x-email.paragraph>

    <x-email.panel>
        <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">What we tried to send</div>
        <x-email.rows>
            <x-email.row label="Amount" :value="($payout->currency ?: config('custody.currency', 'NGN')).' '.$amount" strong />
            <x-email.row label="Bank" :value="($bankName ?? 'Your bank account').($bankCode ? ' ('.$bankCode.')' : '')" />
            @if($reference)
                <x-email.row label="Reference" :value="$reference" mono />
            @endif
        </x-email.rows>
    </x-email.panel>

    @if($reason || $code)
        <x-email.callout tone="neutral" title="What the bank said">
            @if($code)
                <div style="margin:0 0 4px;font-size:12px;line-height:18px;">Code: {{ $code }}</div>
            @endif
            <div style="white-space:pre-wrap;">{{ $reason ?: 'No further detail was given.' }}</div>
        </x-email.callout>
    @endif

    <x-email.paragraph>
        If the reason points at your bank account — a wrong number, a name that does not match, an account that cannot receive transfers — updating it in your dashboard is all that is needed, and the money is sent again automatically. If the reason is unclear, contact us and we will look at it with you.
    </x-email.paragraph>
</x-email.layout>
