<x-email.layout
    title="Business Account Suspended"
    preheader="Your business account has been suspended."
    eyebrow="Account"
    heading="Business account suspended"
    badge="Suspended"
    tone="danger">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>Your business account has been suspended. See details below:</x-email.paragraph>

    <x-email.callout tone="danger" title="Reason">
        <div style="white-space:pre-wrap;">{{ $reason }}</div>
    </x-email.callout>

    <x-email.paragraph>
        If you believe this was a mistake or after addressing the issue, please reply to this email so our team can review and assist you further.
    </x-email.paragraph>
</x-email.layout>
