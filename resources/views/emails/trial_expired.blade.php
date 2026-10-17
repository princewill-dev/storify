<x-email.layout
    title="Trial Expired"
    preheader="Your free trial has ended and your stores are offline."
    eyebrow="Billing"
    heading="Free trial expired"
    badge="Expired"
    tone="danger">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.callout tone="danger" title="Your free trial has ended">
        Your stores are now <strong>offline</strong> and not visible to customers.
    </x-email.callout>

    <x-email.paragraph>
        Don't worry — your store data, products, and settings are all still saved. You can reactivate your stores instantly by subscribing to a paid plan.
    </x-email.paragraph>

    <x-email.heading>What happened</x-email.heading>

    <x-email.panel>
        <ul style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:22px;color:#0F172A;">
            <li>Your store status has been changed to <strong>pending</strong></li>
            <li>Customers can no longer visit your store or place orders</li>
            <li>Your products are no longer visible in search results</li>
        </ul>
    </x-email.panel>

    <x-email.heading>To get back online</x-email.heading>

    <x-email.paragraph>
        Simply log in and subscribe to a plan to reactivate your stores immediately.
    </x-email.paragraph>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/subscription/payment') }}">Reactivate My Stores</x-email.button>

    <x-email.paragraph muted>
        Need help? Our support team is here for you.
    </x-email.paragraph>
</x-email.layout>
