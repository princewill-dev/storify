<x-email.layout
    title="Welcome"
    :preheader="'Welcome to '.$appName.'!'"
    eyebrow="Welcome"
    :heading="'Welcome to '.$appName.'!'"
    subtitle="Thank you for joining us. We're excited to have you on board!"
    :brand="[]">

    <x-email.paragraph>Hello {{ $user->name }}!</x-email.paragraph>

    <x-email.paragraph>
        Your account has been successfully created. You can now enjoy all the benefits of being a member:
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Fast checkout" value="Save your details for quicker purchases" />
            <x-email.row label="Order tracking" value="Track your orders in real-time" />
            <x-email.row label="Order history" value="View all your past orders" />
            <x-email.row label="Exclusive offers" value="Get notified about special deals" />
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ $loginUrl }}">Start shopping</x-email.button>

    <x-email.paragraph muted>
        <strong>Your account details:</strong><br>
        Email: {{ $user->email }}
    </x-email.paragraph>

    <x-email.paragraph muted>
        If you didn't create this account, please contact our support team immediately.
    </x-email.paragraph>
</x-email.layout>
