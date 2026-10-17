<x-email.layout
    title="KYC submission received"
    preheader="We’ve received your KYC submission."
    eyebrow="Verification"
    heading="We’ve received your KYC"
    badge="Received"
    tone="info">

    {{--
        Two sentences and a button, and deliberately nothing else.

        A table used to sit between them listing the submitted-on date, the
        document type and the document number. For a BVN or a NIN the last of
        those is a regulated identifier, and mail is not a channel this platform
        controls: it is stored, forwarded, indexed, and read on devices that are
        somebody else's. An acknowledgement is not worth a permanent copy of a
        person's identity number sitting in an inbox for ever.

        Nothing is lost by removing it. The reader knows all they need to — that it
        arrived and that they will hear back — and everything the review needs is
        in the console, behind a permission and an audit log.
    --}}
    <x-email.paragraph>
        Hi {{ $user->name }}, we’ve received your KYC submission and will let you know as soon as it has been reviewed.
    </x-email.paragraph>

    <x-email.paragraph>
        In the meantime, you can keep setting up your store.
    </x-email.paragraph>

    <x-email.button href="{{ \App\Support\SpaUrls::management('/settings/payment-settings') }}">Continue your setup</x-email.button>
</x-email.layout>
