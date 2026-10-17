<x-email.layout
    title="Action needed — we cannot send your payouts"
    preheader="Your payout account needs updating before we can send {{ $wallet }}."
    eyebrow="Payout"
    heading="Action needed — we cannot send your payouts"
    subtitle="{{ $business->name }}"
    badge="Blocked"
    tone="warning">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        We have now tried {{ $attempts }} {{ $attempts === 1 ? 'time' : 'times' }} to send money for
        <strong>{{ $business->name }}</strong> to the bank account you have on file, and it has been refused
        every time. Rather than keep asking, we have stopped. Your money is safe and untouched — but it
        cannot reach you until the account is corrected.
    </x-email.paragraph>

    <x-email.callout tone="warning" title="Waiting to be paid to you">
        <div style="font-size:22px;line-height:28px;font-weight:700;">{{ $currency }} {{ $wallet }}</div>
        <div style="margin-top:4px;font-size:12px;line-height:18px;">This will be sent to you as soon as this is fixed.</div>
    </x-email.callout>

    <x-email.panel>
        <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">The account we are paying to</div>
        <x-email.rows>
            <x-email.row label="Bank" :value="($bankName ?? 'Not on file').($bankCode ? ' ('.$bankCode.')' : '')" />
            <x-email.row label="Account number" :value="$accountNumberMasked ?: 'Not on file'" mono />
            <x-email.row label="Account name" :value="$accountName ?: 'Not on file'" />
        </x-email.rows>
    </x-email.panel>

    @if($reason)
        <x-email.callout tone="neutral" title="What the bank keeps saying">
            <div style="white-space:pre-wrap;">{{ $reason }}</div>
        </x-email.callout>
    @endif

    <x-email.paragraph>
        This usually means the account number is wrong, the account is closed or cannot receive transfers, or the account name does not match what the bank holds. Whatever the cause, please check your payout account in your dashboard and update it — the details above are the ones failing.
    </x-email.paragraph>

    <x-email.paragraph>
        Once you have updated it, reply to this email or contact our team so we can confirm the new account and restart your payouts. Until then, nothing will be sent.
    </x-email.paragraph>
</x-email.layout>
