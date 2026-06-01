You've been invited to join {{ $user->business?->name ?? 'the team' }} as a staff member.

Click the link below to accept the invitation and get started:

{{ $acceptUrl }}

@if($plainPassword)
Your account has been set up with the following credentials:

  Email: {{ $user->email }}
  Password: {{ $plainPassword }}

Please change your password after your first login for security.
@else
You will be asked to create a password when you accept the invitation.
@endif

This invitation link is unique to you and should not be shared.

If you did not expect this invitation, please ignore this email.
