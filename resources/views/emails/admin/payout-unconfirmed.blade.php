<x-email.layout
    :title="'Payout unconfirmed for '.($business->name ?? 'a business')"
    preheader="A transfer for {{ $business->name ?? 'a business' }} is out and unconfirmed."
    eyebrow="Operations"
    heading="A transfer is out and the provider has not confirmed it"
    badge="Unconfirmed"
    tone="warning">

    {{--
        The instruction comes before the detail, and it is the opposite of the
        instruction on `payouts-stalled`. There, a refusal means the money came
        back and retrying is the correct next move; here the transfer may well
        have landed, so a resend is how a business gets paid twice. Nothing in
        this mail may read as "nothing was lost" or offer a retry, because
        neither is true and the second is expensive.

        The amount and the reference are the two facts an operator needs to find
        this transfer in the provider's own dashboard, which is why they are in
        the box below rather than only in the subject.
    --}}
    <x-email.paragraph>
        We sent a payout for <strong>{{ $business->name ?? 'business #'.$business->id }}</strong>
        and the provider accepted it without ever telling us whether it reached the bank. We asked
        {{ $checks }} {{ \Illuminate\Support\Str::plural('time', $checks) }}@if($openFor) over the {{ $openFor }}@endif
        and it has not said.
    </x-email.paragraph>

    <x-email.callout tone="warning">
        <p style="margin:0 0 6px;font-size:14px;line-height:20px;font-weight:600;">
            The money is out of the wallet and we cannot confirm where it went.
        </p>
        <p style="margin:0;font-size:14px;line-height:20px;">
            <strong>Do not resend this transfer yet.</strong> Check the provider's own dashboard for the reference
            below first — if it landed, sending it again pays the business twice, and a completed transfer cannot
            be called back.
        </p>
    </x-email.callout>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Business">
                {{ $business->name ?? '—' }}@if(!empty($business->business_code)) <span style="color:#64748B;">({{ $business->business_code }})</span>@endif
            </x-email.row>
            <x-email.row label="Amount" :value="$currency.' '.$amount" strong />
            <x-email.row label="Reference" :value="$payout->reference" mono />
            <x-email.row label="Provider">
                {{ $provider }}@if(!empty($payout->provider_payout_id)) <span style="color:#64748B;">— {{ $payout->provider_payout_id }}</span>@endif
            </x-email.row>
            {{--
                The provider's own word, quoted rather than mapped onto ours. A
                status we have not seen before is more useful here as itself: the
                operator is about to look at the provider's dashboard, and the
                string they will read there is this one. When there is none, say
                so — an empty cell reads as a field the mail forgot to fill.
            --}}
            <x-email.row label="Provider's last word" :top="true">
                @if($providerStatus)
                    <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;">{{ $providerStatus }}</span>
                @else
                    <span style="color:#64748B;">None — the provider has never confirmed a status for this transfer.</span>
                @endif
            </x-email.row>
            <x-email.row label="Times we have asked" :value="$checks" />
            <x-email.row label="Sent">
                {{ $payout->sent_at?->toDayDateTimeString() ?? 'Not recorded' }}@if($openFor) <span style="color:#64748B;">— {{ $openFor }} ago</span>@endif
            </x-email.row>
            {{--
                Ours, not the provider's: the send threw and this is what it
                threw, which is the one case where the provider was never asked
                anything and "no status" needs a reason. Capped when it was
                written to the row, because a client library's exception can be
                very long.
            --}}
            @if(!empty($payout->failure_reason))
                <x-email.row label="Why we have no answer" :top="true">
                    <span style="color:#334155;">{{ $payout->failure_reason }}</span>
                </x-email.row>
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ $reviewUrl }}">Open the payout queue</x-email.button>

    <x-email.paragraph muted>
        The queue has a <strong>Check now</strong> beside every transfer still in flight, which asks the provider
        again on the spot. Confirm what happened at the provider before anything is resent.
    </x-email.paragraph>
</x-email.layout>
