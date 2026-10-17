<x-email.layout
    title="KYC Verification Approved"
    preheader="Your identity verification has been approved."
    eyebrow="Verification"
    heading="KYC verification approved"
    badge="Approved"
    tone="success">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        Your identity verification has been <strong>approved</strong>. You can now activate your stores and start selling.
    </x-email.paragraph>

    <x-email.callout tone="success">
        Your stores are ready to go live. Visit your dashboard to activate them.
    </x-email.callout>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/') }}">Go to dashboard</x-email.button>

    <x-email.paragraph muted>
        Thanks,<br>
        {{ config('app.name') }}
    </x-email.paragraph>
</x-email.layout>
