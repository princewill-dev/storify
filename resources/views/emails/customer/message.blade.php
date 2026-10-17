<x-email.layout
    title="Message from your store"
    preheader="A message from {{ $business_name }}."
    eyebrow="Message"
    heading="A message from {{ $business_name }}"
    :brand="['name' => $business_name]"
    :powered-by="true">

    <x-email.paragraph>Hello {{ $customer->first_name }},</x-email.paragraph>

    <x-email.panel>
        <div style="white-space:pre-wrap;font-size:15px;line-height:24px;color:#334155;">{{ $message_body }}</div>
    </x-email.panel>

    @if($show_opt_out)
        <x-email.paragraph muted>
            You are receiving this because you agreed to hear about news and offers
            from {{ $business_name }}. You can change that at any time under
            Account &rarr; Preferences.
        </x-email.paragraph>
    @endif
</x-email.layout>
