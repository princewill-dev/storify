<x-email.layout
    title="We received your message"
    preheader="Thanks for reaching out. We have your message."
    eyebrow="Support"
    heading="We received your message"
    badge="Received"
    tone="success">

    <x-email.paragraph>Dear {{ $data['name'] }},</x-email.paragraph>

    <x-email.paragraph>
        Thanks for reaching out to us. This is a confirmation that we have received your message regarding "<strong>{{ $data['subject'] }}</strong>".
    </x-email.paragraph>

    <x-email.paragraph>
        Our team will review your inquiry and get back to you shortly.
    </x-email.paragraph>

    <x-email.panel>
        <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Your message</div>
        <div style="font-size:14px;line-height:22px;color:#0F172A;white-space:pre-wrap;">{{ $data['message'] }}</div>
    </x-email.panel>

    <x-email.paragraph muted>
        Best regards,<br>The Support Team
    </x-email.paragraph>
</x-email.layout>
