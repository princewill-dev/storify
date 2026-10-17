<x-email.layout
    title="Password Reset OTP"
    preheader="Use your one-time code to reset your password."
    eyebrow="Security"
    heading="Password reset"
    subtitle="Use the code below to reset your password"
    badge="Secure"
    tone="info">

    <x-email.paragraph>Hello {{ $user->name }},</x-email.paragraph>

    <x-email.paragraph>
        We received a request to reset your password. Use the OTP code below to proceed with resetting your password:
    </x-email.paragraph>

    <x-email.panel padding="28px 20px">
        <div style="text-align:center;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;margin-bottom:8px;">Your OTP code</div>
        <div style="text-align:center;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:36px;line-height:44px;font-weight:700;letter-spacing:8px;color:#0B0B0E;">{{ $otp }}</div>
    </x-email.panel>

    <x-email.paragraph>
        This code will expire in <strong>10 minutes</strong>. If you didn't request a password reset, please ignore this email or contact support if you have concerns.
    </x-email.paragraph>

    <x-email.callout tone="warning" title="Security tip">
        Never share your OTP code with anyone. Our team will never ask for your OTP.
    </x-email.callout>

    <x-email.paragraph muted>
        Best regards,<br>
        <strong>{{ config('app.name') }} Team</strong>
    </x-email.paragraph>
</x-email.layout>
