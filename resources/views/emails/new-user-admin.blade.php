<x-email.layout
    :title="'New User Registration'"
    preheader="A new user has registered on your platform."
    eyebrow="Admin"
    heading="New user registration"
    badge="New"
    tone="info">

    <x-email.callout tone="info" title="New user alert">
        A new user has registered on your platform.
    </x-email.callout>

    <x-email.heading>User information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Name" :value="$user->name" strong />
            <x-email.row label="Email" :value="$user->email" />
            <x-email.row label="Registration date" :value="$user->created_at->format('F d, Y H:i:s')" />
            <x-email.row label="User ID" :value="$user->id" mono />
            @if($user->role)
                <x-email.row label="Role" :value="ucfirst($user->role)" />
            @endif
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ $adminUrl }}">View user in admin panel</x-email.button>
</x-email.layout>
