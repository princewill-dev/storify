<x-email.layout
    title="Reply to Your Support Message"
    preheader="We have replied to your support message."
    eyebrow="Support"
    heading="We've replied to your message"
    :brand="$supportMessage->store ? [
        'name' => $supportMessage->store->name,
        'logo' => $supportMessage->store->logoUrl(),
        'email' => $supportMessage->store->support_email,
        'phone' => $supportMessage->store->support_phone,
        'address' => $supportMessage->store->address,
    ] : []"
    powered-by
    badge="Replied"
    tone="info">

    <x-email.paragraph>Dear <strong>{{ $supportMessage->name }}</strong>,</x-email.paragraph>

    <x-email.paragraph>
        Thank you for your patience. We have reviewed your message and here is our response:
    </x-email.paragraph>

    <x-email.heading>Your original message</x-email.heading>

    <x-email.panel>
        <div style="border-left:3px solid #CBD5E1;padding:0 0 0 14px;font-size:14px;line-height:22px;color:#475569;white-space:pre-wrap;">{{ $supportMessage->message }}</div>
    </x-email.panel>

    <x-email.heading>Our response</x-email.heading>

    <x-email.panel>
        <div style="border-left:3px solid #2563EB;padding:0 0 0 14px;font-size:15px;line-height:24px;color:#0F172A;white-space:pre-wrap;">{{ $supportMessage->reply }}</div>
    </x-email.panel>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Replied by" :value="ucfirst($supportMessage->replied_by_type).' Team'" />
            <x-email.row label="Reply date" :value="$supportMessage->replied_at->format('M d, Y \a\t h:i A')" />
            <x-email.row label="Reference ID" :value="'#'.$supportMessage->id" mono />
            <x-email.row label="Store" :value="$supportMessage->store?->name ?? '—'" />
            @if($supportMessage->store?->support_email)
                <x-email.row label="Email" :value="$supportMessage->store->support_email" />
            @endif
            @if($supportMessage->store?->support_phone)
                <x-email.row label="Phone" :value="$supportMessage->store->support_phone" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph>
        If you have any further questions or need additional assistance, please don't hesitate to reach out to us again.
    </x-email.paragraph>

    <x-email.paragraph muted>
        We're here to help you with any questions or concerns.
    </x-email.paragraph>
</x-email.layout>
