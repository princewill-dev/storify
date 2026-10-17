<x-email.layout
    title="New KYC submission"
    preheader="A business owner just submitted KYC information."
    eyebrow="Verification"
    heading="New KYC submission"
    badge="Review"
    tone="warning">

    <x-email.paragraph>
        {{ $user?->name ?? 'A business owner' }} just submitted KYC information. Review and take action when you can.
    </x-email.paragraph>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Business" :value="$user?->name ?? '—'" />
            <x-email.row label="Email" :value="$user?->email ?? '—'" />
            <x-email.row label="Document type" :value="$application->documentType?->name ?? '—'" />
            {{--
                Masked, not printed. A BVN or a NIN is a regulated identifier
                and mail is not a channel this platform controls — it is
                stored, forwarded and indexed, and the inbox it lands in is not
                ours. The reviewer does not need the number here: they need to
                know an application is waiting, and the console shows them the
                whole of it behind a permission and an audit row. Enough digits
                to recognise a record, and no more.
            --}}
            <x-email.row label="Document ID" :value="$application->kyc_document_id ? '••••'.substr((string) $application->kyc_document_id, -4) : '—'" mono />
            <x-email.row label="Submitted at" :value="optional($application->submitted_at)->format('j M Y, g:ia') ?? now()->format('j M Y, g:ia')" />
        </x-email.rows>
    </x-email.panel>

    <x-email.button href="{{ \App\Support\SpaUrls::admin('/kyc-applications/'.$application->id) }}">Review submission</x-email.button>
</x-email.layout>
