<x-email.layout
    title="New Support Message"
    preheader="A new support message requires your attention."
    eyebrow="Support"
    heading="New support message"
    subtitle="Requires your attention"
    badge="Action required"
    tone="warning">

    <x-email.callout tone="info" title="New support message">
        New support message received from {{ $supportMessage->store?->name ?? config('app.name') }}
    </x-email.callout>

    <x-email.heading>Customer information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Name" :value="$supportMessage->name" strong />
            <x-email.row label="Email" :value="$supportMessage->email" />
            @if($supportMessage->phone)
                <x-email.row label="Phone" :value="$supportMessage->phone" />
            @endif
            <x-email.row label="Store" :value="$supportMessage->store?->name ?? '—'" />
            <x-email.row label="Date" :value="$supportMessage->created_at->format('M d, Y \a\t h:i A')" />
            <x-email.row label="Message ID" :value="'#'.$supportMessage->id" mono />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Message content</x-email.heading>

    <x-email.panel>
        <div style="font-size:15px;line-height:24px;color:#0F172A;white-space:pre-wrap;">{{ $supportMessage->message }}</div>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::admin('/support-messages') }}">View &amp; Reply in Dashboard</x-email.button>

    <x-email.paragraph muted>
        Please respond to the customer within 24-48 hours.
    </x-email.paragraph>
</x-email.layout>
