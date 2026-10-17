<x-email.layout
    title="Trial Expiring Soon"
    preheader="Your free trial is coming to an end."
    eyebrow="Billing"
    heading="Your trial is ending soon"
    badge="Trial"
    tone="warning">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    @if($daysRemaining > 0)
        <x-email.callout tone="warning" :title="$daysRemaining.' day'.($daysRemaining > 1 ? 's' : '').' remaining'">
            Your free trial ends on {{ $user->trial_ends_at?->format('M d, Y') }}
        </x-email.callout>
    @else
        <x-email.callout tone="danger" title="Your trial expires today!">
            Your store will go offline soon if you don't upgrade
        </x-email.callout>
    @endif

    <x-email.paragraph>
        To keep your store online and continue accepting orders, upgrade to a paid plan today.
    </x-email.paragraph>

    <x-email.heading>What you'll lose if your trial expires</x-email.heading>

    <x-email.panel>
        <ul style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:22px;color:#0F172A;">
            <li>Your store will go <strong>offline</strong></li>
            <li>Customers won't be able to place orders</li>
            <li>Your product listings will be hidden</li>
        </ul>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/subscription/payment') }}">Upgrade Now</x-email.button>

    <x-email.paragraph muted>
        If you have any questions, our support team is happy to help.
    </x-email.paragraph>
</x-email.layout>
