<x-email.layout
    title="New Order Notification"
    preheader="You have received a new order #{{ $order->order_number }}."
    eyebrow="Order"
    heading="New order received"
    subtitle="Order #{{ $order->order_number }}"
    badge="New"
    tone="info"
    :brand="$order->store ? ['name' => $order->store->name, 'logo' => $order->store->logoUrl(), 'email' => $order->store->support_email, 'phone' => $order->store->support_phone, 'address' => $order->store->address] : []"
    :powered-by="(bool) $order->store">

    <x-email.paragraph>Hello,</x-email.paragraph>

    <x-email.paragraph>
        You have received a new order (<strong>#{{ $order->order_number }}</strong>) from your store <strong>{{ $order->store?->name ?? 'Your Store' }}</strong>.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Customer" :value="$order->customer->first_name.' '.$order->customer->last_name" />
            <x-email.row label="Total amount" value="₦{{ number_format($order->total, 2) }}" strong />
            <x-email.row label="Date" :value="$order->created_at->format('M d, Y H:i')" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Items</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            @foreach($order->items as $item)
                <x-email.row :label="$item->quantity.'x '.$item->product_name" :value="'₦'.number_format($item->subtotal, 2)" :top="true" />
            @endforeach
        </x-email.rows>
    </x-email.panel>

    <x-email.paragraph>Please login to your business dashboard to process this order.</x-email.paragraph>

    <x-email.paragraph>Thank you!</x-email.paragraph>
</x-email.layout>
