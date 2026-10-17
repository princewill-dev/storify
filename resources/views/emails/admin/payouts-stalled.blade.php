<x-email.layout
    :title="'Payouts stopped for '.($business->name ?? 'a business')"
    preheader="Sending has stopped for {{ $business->name ?? 'a business' }} after {{ $failures }} failed attempts."
    eyebrow="Operations"
    heading="Payouts have stopped for a business"
    badge="Stalled"
    tone="danger">

    {{--
        The reassurance comes before the detail on purpose. Every attempt was
        refused and every refusal was credited back, so the first thing an
        operator must be able to read is "no money is missing" — otherwise the
        first move is an incident call that isn't one, and the actual question
        ("what is refusing this?") gets answered second.

        Which reassurance is true depends on whether a transfer was ever made.
        With no `$payout` nothing was sent at all, so promising that "each refusal
        was credited back" would describe a refund that never happened.
    --}}
    <x-email.paragraph>
        We have stopped trying to send money to
        <strong>{{ $business->name ?? 'business #'.$business->id }}</strong>
        after {{ $failures }} failed {{ \Illuminate\Support\Str::plural('attempt', $failures) }} in a row.
        @if($payout)
            Nothing has been lost: each refusal returned the money to their wallet, where it is still owed
            and still visible to them. Sending resumes when an operator retries from the payout queue.
        @else
            Nothing has been lost and nothing was ever sent — the provider could not be reached, so the money
            never left their wallet, where it is still owed and still visible to them. Sending resumes when
            an operator retries from the payout queue.
        @endif
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Business">
                {{ $business->name ?? '—' }}@if(!empty($business->business_code)) <span style="color:#64748B;">({{ $business->business_code }})</span>@endif
            </x-email.row>
            <x-email.row label="Held in their wallet" :value="$currency.' '.$held" strong />
            <x-email.row label="Reason sending stopped" :value="$blocker['label'] ?? $blocker['code'] ?? 'unknown'" />
            <x-email.row label="Failed attempts" :value="$failures" />
            <x-email.row label="Last attempt" :value="$payout?->reference ?? 'No transfer was created'" mono />
            {{--
                The provider's own words, quoted rather than summarised. A
                paraphrase here would put a second opinion between the operator
                and the only evidence of what actually happened. No account
                number: mail is a channel this platform does not control, and the
                console has the number behind a permission and an audit row.

                Guarded on `$payout` as well as on the fields: reading a property
                of null warns rather than returning null, so `!empty()` alone
                would not make this row safe.
            --}}
            @if($payout !== null && (!empty($payout->failure_code) || !empty($payout->failure_reason)))
                <x-email.row label="Provider said" :top="true">
                    @if(!empty($payout->failure_code))
                        <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;">{{ $payout->failure_code }}</div>
                    @endif
                    @if(!empty($payout->failure_reason))
                        <div style="color:#334155;">{{ $payout->failure_reason }}</div>
                    @endif
                </x-email.row>
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ $reviewUrl }}">Open the payout queue</x-email.button>

    <x-email.paragraph muted>
        Retry sends it again immediately, without changing the amount or the account it goes to.
    </x-email.paragraph>
</x-email.layout>
