<x-email.layout
    title="Order Status Update"
    preheader="Order #{{ $order->order_number }} is now {{ $newStatus }}."
    eyebrow="Order"
    heading="Order status updated"
    subtitle="Order #{{ $order->order_number }} has been updated"
    :badge="ucfirst($newStatus)"
    :tone="in_array($newStatus, ['cancelled', 'returned']) ? 'danger' : (in_array($newStatus, ['delivered', 'completed']) ? 'success' : 'info')"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.paragraph>Hello{{ $customer?->first_name ? ' ' . $customer->first_name : '' }},</x-email.paragraph>

    <x-email.paragraph>Your order status has changed:</x-email.paragraph>

    <x-email.paragraph>
        <x-email.pill :tone="in_array($newStatus, ['cancelled', 'returned']) ? 'danger' : (in_array($newStatus, ['delivered', 'completed']) ? 'success' : 'info')">{{ ucfirst($newStatus) }}</x-email.pill>
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Order number" :value="$order->order_number" strong />
            <x-email.row label="Total amount" value="₦{{ number_format($order->total, 2) }}" strong />
            <x-email.row label="Items" :value="$order->items->count().' item(s)'" />
            <x-email.row label="Updated on" :value="now()->format('F d, Y \a\t g:i A')" />
        </x-email.rows>
    </x-email.panel>

    @if($newStatus === 'dispatched')
        <x-email.callout tone="info" title="Your order is on the way!">
            Your items have been dispatched and are being delivered. Track your order for real-time updates.
        </x-email.callout>
    @elseif($newStatus === 'delivered')
        <x-email.callout tone="success" title="Order delivered!">
            We hope you love your purchase. If you have any issues, please contact support.
        </x-email.callout>
    @elseif($newStatus === 'cancelled')
        <x-email.callout tone="danger" title="Order cancelled.">
            If you didn't request this cancellation, please contact support immediately.
        </x-email.callout>
    @else
        <x-email.callout tone="info" title="What's next?">
            We'll keep you updated as your order progresses. You can track your order anytime.
        </x-email.callout>
    @endif

    <x-email.button href="{{ $order->store ? \App\Support\SpaUrls::storefront($order->store->slug, '/track?order='.$order->order_number) : config('app.url') }}">Track your order</x-email.button>

    <x-email.paragraph muted>
        If you have any questions, please contact the store directly.
    </x-email.paragraph>
</x-email.layout>
