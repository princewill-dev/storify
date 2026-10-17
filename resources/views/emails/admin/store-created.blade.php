<x-email.layout
    title="New Store Created"
    preheader="A new store has been created by {{ auth()->user()->name ?? 'an admin' }}."
    eyebrow="Admin"
    heading="New store created"
    badge="New"
    tone="info">

    <x-email.paragraph>
        A new store has been created by {{ auth()->user()->name ?? 'an admin' }}.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Store" :value="$store->name" strong />
            <x-email.row label="Store ID" :value="$store->store_id" mono />
            <x-email.row label="Business" :value="$user?->name ?? '—'" />
            <x-email.row label="Status"><x-email.pill tone="info">{{ $store->status }}</x-email.pill></x-email.row>
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::admin('/stores') }}">View stores</x-email.button>
</x-email.layout>
