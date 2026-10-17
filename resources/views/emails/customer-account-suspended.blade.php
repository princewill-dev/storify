<x-email.layout
    title="Account Suspended"
    preheader="Your account has been temporarily suspended."
    eyebrow="Account"
    heading="Account suspended"
    badge="Suspended"
    tone="danger">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.callout tone="warning" title="Important notice">
        Your account has been temporarily suspended by our administration team.
    </x-email.callout>

    <x-email.panel>
        <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Account details</div>
        <x-email.rows>
            <x-email.row label="Name" :value="$customer->full_name" />
            <x-email.row label="Email" :value="$customer->email" />
            @if($customer->phone)
                <x-email.row label="Phone" :value="$customer->phone" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.callout tone="danger" title="Reason for suspension">
        {{ $reason }}
    </x-email.callout>

    <x-email.heading>What this means</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="New orders" value="You will not be able to place new orders" />
            <x-email.row label="Dashboard" value="You cannot access your account dashboard" />
            <x-email.row label="Existing orders" value="Existing orders will continue to be processed" />
            <x-email.row label="Your data" value="Your account data remains secure" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>What you should do</x-email.heading>

    <x-email.paragraph>
        If you believe this suspension was made in error or if you would like to discuss this matter, please contact our support team immediately.
    </x-email.paragraph>

    <x-email.paragraph muted>
        <strong>Contact support:</strong><br>
        Email: {{ config('mail.from.address') }}<br>
        We typically respond within 24 hours.
    </x-email.paragraph>

    <x-email.paragraph>
        We take account security and policy compliance seriously. We appreciate your understanding and cooperation in this matter.
    </x-email.paragraph>
</x-email.layout>
