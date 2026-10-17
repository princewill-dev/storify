<x-email.layout
    title="Your Store is Live"
    preheader="Your store has been created and is ready to sell."
    eyebrow="Store"
    heading="Your store is live 🎉"
    subtitle="Your store has been created and is ready to sell."
    badge="Live"
    tone="success">

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Store" :value="$store->name" strong />
            <x-email.row label="Store ID" :value="$store->store_id" mono />
            <x-email.row label="Status">
                <x-email.pill :tone="$store->status === 'active' ? 'success' : 'neutral'">{{ $store->status }}</x-email.pill>
            </x-email.row>
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/') }}">Manage store</x-email.button>
</x-email.layout>
