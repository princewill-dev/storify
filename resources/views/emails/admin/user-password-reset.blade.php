<x-email.layout
    title="Your password has been reset"
    preheader="Your account password has been reset by an administrator."
    eyebrow="Account"
    heading="Your password has been reset"
    badge="Security"
    tone="warning">

    <x-email.paragraph>
        Hello {{ $user->name ?: 'there' }}, an administrator has reset the password for your account. Use the temporary password below to sign in — you will be asked to choose a new password right away.
    </x-email.paragraph>

    <x-email.panel>
        <div style="font-size:11px;line-height:16px;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Email</div>
        <div style="font-size:15px;line-height:22px;font-weight:600;color:#0F172A;">{{ $user->email }}</div>
        <div style="margin-top:10px;font-size:11px;line-height:16px;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">Temporary password</div>
        <div style="font-size:17px;line-height:24px;font-weight:700;color:#0F172A;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $temporaryPassword }}</div>
    </x-email.panel>

    <x-email.button href="{{ $loginUrl }}">Sign in</x-email.button>

    <x-email.paragraph muted>
        If you did not request this change, contact support immediately at {{ $company['support_email'] }}.
    </x-email.paragraph>
</x-email.layout>
