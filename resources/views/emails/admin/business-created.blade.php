<x-email.layout
    title="New Business Created"
    preheader="A new business has been added by {{ auth()->user()->name ?? 'an admin' }}."
    eyebrow="Admin"
    heading="New business created"
    badge="New"
    tone="info">

    <x-email.paragraph>
        A new business has been added by {{ auth()->user()->name ?? 'an admin' }}.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Business name" :value="$user->name" strong />
            <x-email.row label="Email" :value="$user->email ?? '—'" />
            <x-email.row label="Phone" :value="$user->phone ?? '—'" />
            <x-email.row label="Status"><x-email.pill tone="info">{{ $user->status }}</x-email.pill></x-email.row>
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::admin('/businesses') }}">View businesses</x-email.button>
</x-email.layout>
