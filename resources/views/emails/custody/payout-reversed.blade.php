<x-email.layout
    title="Payout returned"
    preheader="A payout for {{ $business->name }} came back. You are still owed this money."
    eyebrow="Payout"
    heading="Payout returned"
    subtitle="{{ $business->name }}"
    badge="Returned"
    tone="warning">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        A payout we sent for <strong>{{ $business->name }}</strong> has been sent back to us by the bank. It did leave us and reach the banking network, so if you saw it arrive and then leave again, that is why.
    </x-email.paragraph>

    <x-email.callout tone="warning" title="You are still owed this money.">
        It is safe and will be sent again automatically.
    </x-email.callout>

    <x-email.panel>
        <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">The transfer that came back</div>
        <x-email.rows>
            <x-email.row label="Amount" :value="($payout->currency ?: config('custody.currency', 'NGN')).' '.$amount" strong />
            <x-email.row label="Bank" :value="$bankName ?? 'Your bank account'" />
            @if($accountName)
                <x-email.row label="Account name" :value="$accountName" />
            @endif
            @if($reference)
                <x-email.row label="Reference" :value="$reference" mono />
            @endif
        </x-email.rows>
    </x-email.panel>

    @if($reason)
        <x-email.callout tone="neutral" title="What the bank said">
            <div style="white-space:pre-wrap;">{{ $reason }}</div>
        </x-email.callout>
    @endif

    <x-email.paragraph>
        A returned transfer often means the receiving account could not take the funds rather than that anything is wrong with your details. We will simply try again, so please check your payout account is the one you want now — otherwise we may send it, have it returned, and send it once more.
    </x-email.paragraph>
</x-email.layout>
