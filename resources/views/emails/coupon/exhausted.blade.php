<x-email.layout
    title="Coupon fully redeemed"
    preheader="A coupon code has reached its usage limit."
    eyebrow="Billing"
    heading="Coupon fully redeemed"
    badge="Redeemed"
    tone="warning">

    <x-email.paragraph>
        Coupon code
        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#F1F5F9;padding:2px 8px;border-radius:6px;color:#0F172A;">{{ $coupon->code }}</span>
        {{ $coupon->name ? '(' . $coupon->name . ')' : '' }} has reached its usage limit.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Total uses" :value="$coupon->uses_count.' / '.$coupon->max_uses" strong />
            <x-email.row label="Discount" :value="$coupon->discount_label" />
            <x-email.row label="Plan" :value="$coupon->subscriptionPlan?->name ?? 'All Plans'" />
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph muted>
        The coupon has been automatically marked as inactive. No further action is required.
    </x-email.paragraph>
</x-email.layout>
