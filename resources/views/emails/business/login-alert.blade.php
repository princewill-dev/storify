<x-email.layout
    title="New login detected"
    preheader="We detected a login to your business account."
    eyebrow="Security"
    heading="New login detected"
    badge="Security"
    tone="warning">

    <x-email.paragraph>Hey {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        We detected a login to your business account. Here are the details we captured:
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Time" :value="now()->toDayDateTimeString()" />
            <x-email.row label="IP address" :value="$ipAddress" mono />
            <x-email.row label="Browser/Device" :value="$userAgent" :top="true" />
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph>
        If this was you, you can safely ignore this email. If you suspect anything suspicious, please reset your password or contact support.
    </x-email.paragraph>

    <x-email.paragraph muted>
        Thanks,<br>
        {{ config('app.name') }} Team
    </x-email.paragraph>
</x-email.layout>
