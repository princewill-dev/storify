<x-email.layout
    title="Store Status Updated"
    preheader="Your store {{ $store->name }} status has changed."
    eyebrow="Store"
    heading="Store status updated"
    subtitle="{{ $store->name }}"
    badge="Suspended"
    tone="danger">

    <x-email.paragraph>
        Your store status has changed. If you have questions, please reply to this email.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Store" :value="$store->name" strong />
            <x-email.row label="Store ID" :value="$store->store_id" mono />
            <x-email.row label="New status"><x-email.pill tone="danger">{{ $store->status }}</x-email.pill></x-email.row>
            @isset($reason)
                <x-email.row label="Reason" :value="$reason" :top="true" />
            @endisset
        </x-email.rows>
    </x-email.panel>
</x-email.layout>
