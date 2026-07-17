<x-mail::message>
# KYC Verification Approved

Hello {{ $user->name }},

Your identity verification has been **approved**. You can now activate your stores and start selling.

<x-mail::panel>
Your stores are ready to go live. Visit your dashboard to activate them.
</x-mail::panel>

<x-mail::button :url="route('management.dashboard')">
Go to Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
