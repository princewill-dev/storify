<x-email.layout
    title="You have been invited to the Storify admin team"
    preheader="Accept your invitation to join the Storify platform admin team."
    eyebrow="Invitation"
    heading="You have been invited"
    badge="Invite"
    tone="info">

    <x-email.paragraph>
        You have been invited to join the Storify platform admin team. Click the button below to accept the invitation and set up your account.
    </x-email.paragraph>

    <x-email.button href="{{ $acceptUrl }}">Accept invitation</x-email.button>

    <x-email.paragraph muted>
        If the button does not work, copy and paste this link into your browser:<br>
        <span style="color:#2563EB;word-break:break-all;">{{ $acceptUrl }}</span>
    </x-email.paragraph>

    <x-email.paragraph muted>
        This invitation link is unique to you and should not be shared. If you did not expect this invitation, you can safely ignore this email.
    </x-email.paragraph>
</x-email.layout>
