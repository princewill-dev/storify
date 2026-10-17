<x-email.layout
    title="Business Account Reactivated"
    preheader="Your business account has been reactivated."
    eyebrow="Account"
    heading="Business account reactivated"
    badge="Active"
    tone="success">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>Your business account has been reactivated. See details below:</x-email.paragraph>

    <x-email.callout tone="success" title="Notes">
        <div style="white-space:pre-wrap;">{{ $reason }}</div>
    </x-email.callout>

    <x-email.paragraph>
        If you need further help, please reply to this email and our team will assist you.
    </x-email.paragraph>
</x-email.layout>
