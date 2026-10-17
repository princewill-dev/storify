<x-email.layout
    :title="'New Live First KYC Application'"
    preheader="A Live First KYC application is waiting for review."
    eyebrow="Verification"
    heading="New Live First KYC application"
    subtitle="Action required: review and approve"
    badge="Urgent"
    tone="danger"
    :footer-note="'This is an automated notification from the Live First program.'">

    <x-email.paragraph>
        A new Live First KYC application has been submitted and requires your review.
    </x-email.paragraph>

    <x-email.heading>Application information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Application ID" :value="$kycId" mono />
            <x-email.row label="Submitted on" :value="$application->submitted_at->format('F d, Y \a\t H:i A')" />
            <x-email.row label="Documents uploaded" :value="$documentsCount.' files'" />
            <x-email.row label="Status" :value="'Pending Review'" tone="warning" strong />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Customer details</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Name" :value="$application->full_name" />
            <x-email.row label="Email" :value="$customer->email" />
            <x-email.row label="Phone" :value="$application->phone_number" />
            <x-email.row label="Date of birth" :value="\Carbon\Carbon::parse($application->date_of_birth)->format('F d, Y')" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Employment information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Employer" :value="$application->employer_name" />
            <x-email.row label="Years with employer" :value="$application->years_with_employer.' years'" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Residential information</x-email.heading>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="State" :value="$application->residential_state" />
            <x-email.row label="LGA" :value="$application->residential_lga" />
            <x-email.row label="Address" :value="$application->residential_address" />
        </x-email.rows>
    </x-email.panel>

    <x-email.heading>Submitted documents</x-email.heading>

    <x-email.panel>
        <ul style="margin:0;padding:0 0 0 20px;font-size:14px;line-height:22px;color:#0F172A;">
            <li>National Identification Number (NIN)</li>
            <li>Old Payslip</li>
            <li>Recent Payslip</li>
            <li>Verification Video</li>
            <li>Selfie with ID</li>
            <li>Appointment Letter</li>
            <li>Bank Authorization Form</li>
        </ul>
    </x-email.panel>

    <x-email.button href="{{ $appUrl }}/office/live-first/applications/{{ $kycId }}">Review Application Now</x-email.button>

    <x-email.paragraph muted>
        <strong>SLA:</strong> Applications should be reviewed within 2-3 business days.
    </x-email.paragraph>
</x-email.layout>
