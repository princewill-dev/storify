<x-email.layout
    title="Account Activated"
    preheader="Your account has been activated."
    eyebrow="Account"
    heading="Welcome back!"
    badge="Active"
    tone="success">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.callout tone="success" title="Great news!">
        Your account has been successfully activated and is now fully operational.
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

    <x-email.heading>Your account is now active</x-email.heading>

    <x-email.paragraph>You can now enjoy full access to all features and services:</x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Browse and shop" value="Shop from our stores" />
            <x-email.row label="Orders" value="Place new orders" />
            <x-email.row label="Tracking" value="Track your order history" />
            <x-email.row label="Settings" value="Manage your account settings" />
            <x-email.row label="Offers" value="Access exclusive deals and promotions" />
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ config('app.url') }}">Start shopping now</x-email.button>

    <x-email.heading>Need help?</x-email.heading>

    <x-email.paragraph>
        If you have any questions or need assistance, our support team is here to help:
    </x-email.paragraph>

    <x-email.paragraph muted>
        <strong>Contact support:</strong><br>
        Email: {{ config('mail.from.address') }}<br>
        We're available to assist you anytime.
    </x-email.paragraph>

    <x-email.paragraph>
        Thank you for being a valued customer. We're excited to serve you again!
    </x-email.paragraph>
</x-email.layout>
