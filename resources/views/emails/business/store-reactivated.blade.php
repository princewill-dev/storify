<x-email.layout
    title="Store Reactivated"
    preheader="Your store {{ $store->name }} has been reactivated."
    eyebrow="Store"
    heading="Store reactivated"
    subtitle="{{ $store->name }}"
    badge="Active"
    tone="success">

    <x-email.paragraph>
        Your store has been reactivated. If you have questions, please reply to this email.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Store" :value="$store->name" strong />
            <x-email.row label="Store ID" :value="$store->store_id" mono />
            <x-email.row label="New status"><x-email.pill tone="success">{{ $store->status }}</x-email.pill></x-email.row>
            @isset($reason)
                <x-email.row label="Notes" :value="$reason" :top="true" />
            @endisset
        </x-email.rows>
    </x-email.panel>
</x-email.layout>
