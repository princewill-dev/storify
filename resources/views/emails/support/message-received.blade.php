<x-email.layout
    title="Support Message Received"
    preheader="We have your support message and will review it shortly."
    eyebrow="Support"
    heading="Message received"
    :brand="$supportMessage->store ? [
        'name' => $supportMessage->store->name,
        'logo' => $supportMessage->store->logoUrl(),
        'email' => $supportMessage->store->support_email,
        'phone' => $supportMessage->store->support_phone,
        'address' => $supportMessage->store->address,
    ] : []"
    powered-by
    badge="Received"
    tone="success">

    <x-email.paragraph>Dear <strong>{{ $supportMessage->name }}</strong>,</x-email.paragraph>

    <x-email.paragraph>
        Thank you for reaching out to us. We have received your support message and our team will review it shortly.
    </x-email.paragraph>

    <x-email.panel>
        <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Your message</div>
        <div style="font-size:14px;line-height:22px;color:#0F172A;">{{ $supportMessage->message }}</div>
    </x-email.panel>

    <x-email.paragraph>
        You can expect a response from us within 24-48 hours. We appreciate your patience.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Reference ID" :value="'#'.$supportMessage->id" mono />
            <x-email.row label="Store" :value="$supportMessage->store?->name ?? '—'" />
            <x-email.row label="Submitted" :value="$supportMessage->created_at->format('M d, Y \a\t h:i A')" />
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph muted>
        Thank you for choosing {{ $supportMessage->store?->name ?? config('app.name') }}.
    </x-email.paragraph>
</x-email.layout>
