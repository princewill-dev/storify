<x-mail::message>
# KYC Verification Not Approved

Hello {{ $user->name }},

We reviewed the identity information you submitted and it was **not approved**.

@if($application->review_notes)
<x-mail::panel>
**Reason:** {{ $application->review_notes }}
</x-mail::panel>
@endif

You can correct the issue and resubmit your KYC information at any time — your previous submission is kept on file for reference.

<x-mail::button :url="route('management.kyc.show')">
Review and Resubmit
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
