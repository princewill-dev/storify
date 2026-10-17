<x-email.layout
    title="Your download is ready"
    preheader="Your downloads for order {{ $order->order_number }} are ready."
    eyebrow="Download"
    heading="Your download is ready"
    subtitle="Order {{ $order->order_number }}"
    badge="Ready"
    tone="success"
    :brand="$store ? ['name' => $store->name, 'logo' => $store->logoUrl(), 'email' => $store->support_email, 'phone' => $store->support_phone, 'address' => $store->address] : []"
    :powered-by="(bool) $store">

    <x-email.paragraph>
        Thank you for your purchase{{ $customer?->first_name ? ', '.$customer->first_name : '' }}. Your payment for order <strong>{{ $order->order_number }}</strong> has been confirmed. Use the button(s) below to download your item(s).
    </x-email.paragraph>

    @foreach($downloads as $download)
        <x-email.panel>
            <div style="margin:0 0 6px;font-size:15px;line-height:22px;font-weight:700;color:#0F172A;">{{ $download['product_name'] }}</div>
            <div style="font-size:12px;line-height:18px;color:#64748B;">
                {{ $download['file_count'] }} file(s) &middot; up to {{ $download['downloads_remaining'] }} download(s)
                @if($download['expires_at'])
                    &middot; link expires {{ $download['expires_at']->format('d M Y') }}
                @endif
            </div>
            <x-email.button href="{{ $download['url'] }}">Download now</x-email.button>
        </x-email.panel>
    @endforeach

    <x-email.paragraph muted>
        Keep this email safe — the download link is unique to your purchase and cannot be shared. If the button does not work, copy and paste the link into your browser.
    </x-email.paragraph>

    <x-email.paragraph muted>
        Need help? Reply to this email or contact {{ $store?->support_email ?? config('mail.from.address') }}.
    </x-email.paragraph>
</x-email.layout>
