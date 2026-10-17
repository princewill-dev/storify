<x-email.layout
    title="New Support Message"
    preheader="A new support message has been received from your website."
    eyebrow="Support"
    heading="New support message"
    subtitle="A new support message has been received from your website."
    badge="New"
    tone="info">

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Name" :value="$data['name']" strong />
            <x-email.row label="Email" :value="$data['email']" />
            <x-email.row label="Phone" :value="$data['phone'] ?? 'N/A'" />
            <x-email.row label="Subject" :value="$data['subject']" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Message</x-email.heading>

    <x-email.panel>
        <div style="font-size:14px;line-height:22px;color:#0F172A;white-space:pre-wrap;">{{ $data['message'] }}</div>
    </x-email.panel>

    <x-email.paragraph muted>
        This email was sent automatically from your website contact form.
    </x-email.paragraph>
</x-email.layout>
