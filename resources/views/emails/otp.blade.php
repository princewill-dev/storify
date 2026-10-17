<x-email.layout
    title="Verification Code"
    preheader="Your {{ $appName }} verification code expires in {{ $expiryMinutes }} minutes."
    eyebrow="Verification"
    heading="Your verification code"
    subtitle="Your {{ $appName }} verification code is:"
    badge="Secure"
    tone="info">

    <x-email.panel padding="28px 20px">
        <div style="text-align:center;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:42px;line-height:48px;font-weight:700;letter-spacing:8px;color:#0B0B0E;">{{ $otpCode }}</div>
    </x-email.panel>

    <x-email.callout tone="warning" title="This code expires in {{ $expiryMinutes }} minutes">
        It can only be used once. Never share this code with anyone.
    </x-email.callout>

    <x-email.paragraph muted>
        If you believe you are getting this email in error or want to close your {{ $appName }} account, please visit our
        <a href="{{ $supportUrl }}" style="color:#2563EB;text-decoration:none;">support site</a>.
        To learn more about {{ $appName }}, please visit
        <a href="{{ $appUrl }}" style="color:#2563EB;text-decoration:none;">{{ $appDomain }}</a>.
    </x-email.paragraph>
</x-email.layout>
