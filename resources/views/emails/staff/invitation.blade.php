<x-email.layout
    title="You've been invited"
    preheader="You've been invited to join {{ $user->business?->name ?? 'the team' }} as a staff member."
    eyebrow="Invitation"
    heading="You've been invited"
    subtitle="{{ $user->business?->name ?? 'the team' }}"
    badge="Invite"
    tone="info">

    <x-email.paragraph>
        You've been invited to join {{ $user->business?->name ?? 'the team' }} as a staff member.
    </x-email.paragraph>

    <x-email.paragraph>
        Click the link below to accept the invitation and get started:
    </x-email.paragraph>

    <x-email.button href="{{ $acceptUrl }}">Accept invitation</x-email.button>

    <x-email.paragraph muted>
        Or copy and paste this link into your browser:<br>
        <span style="color:#2563EB;word-break:break-all;">{{ $acceptUrl }}</span>
    </x-email.paragraph>

    @if($plainPassword)
        <x-email.panel>
            <div style="margin:0 0 6px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Your account has been set up with the following credentials</div>
            <x-email.rows>
                <x-email.row label="Email" :value="$user->email" />
                <x-email.row label="Password" :value="$plainPassword" mono />
            </x-email.rows>
        </x-email.panel>

        <x-email.callout tone="warning">
            Please change your password after your first login for security.
        </x-email.callout>
    @else
        <x-email.paragraph>
            You will be asked to create a password when you accept the invitation.
        </x-email.paragraph>
    @endif

    <x-email.paragraph muted>
        This invitation link is unique to you and should not be shared.
    </x-email.paragraph>

    <x-email.paragraph muted>
        If you did not expect this invitation, please ignore this email.
    </x-email.paragraph>
</x-email.layout>
