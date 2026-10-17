<x-email.layout
    title="KYC Verification Not Approved"
    preheader="Your identity verification was not approved."
    eyebrow="Verification"
    heading="KYC verification not approved"
    badge="Action needed"
    tone="danger">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        We reviewed the identity information you submitted and it was <strong>not approved</strong>.
    </x-email.paragraph>

    @if($application->review_notes)
        <x-email.callout tone="danger" title="Reason">
            {{ $application->review_notes }}
        </x-email.callout>
    @endif

    <x-email.paragraph>
        You can correct the issue and resubmit your KYC information at any time — your previous submission is kept on file for reference.
    </x-email.paragraph>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/kyc') }}">Review and resubmit</x-email.button>

    <x-email.paragraph muted>
        Thanks,<br>
        {{ config('app.name') }}
    </x-email.paragraph>
</x-email.layout>
