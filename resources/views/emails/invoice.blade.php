<x-email.layout
    title="Invoice {{ $invoice->invoice_number }}"
    preheader="Invoice {{ $invoice->invoice_number }} from {{ $invoice->store?->name ?? config('app.name') }}."
    eyebrow="Invoice"
    heading="Invoice {{ $invoice->invoice_number }}"
    subtitle="From {{ $invoice->store?->name ?? config('app.name') }}"
    badge="Invoice"
    tone="neutral"
    :brand="$invoice->store ? ['name' => $invoice->store->name, 'logo' => $invoice->store->logoUrl(), 'email' => $invoice->store->support_email, 'phone' => $invoice->store->support_phone, 'address' => $invoice->store->address] : []"
    :powered-by="(bool) $invoice->store">

    <x-email.details>
        <x-email.detail label="Issued" :value="$invoice->issue_date->format('M d, Y')" />
        <x-email.detail label="Due" :value="$invoice->due_date->format('M d, Y')" />
        <x-email.detail label="To" :value="$invoice->recipient_name ?? $invoice->customer?->full_name" />
    </x-email.details>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-collapse:collapse;font-size:13px;line-height:20px;color:#334155;">
        <thead>
            <tr>
                <th align="left" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Description</th>
                <th align="center" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Qty</th>
                <th align="right" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Price</th>
                <th align="right" style="padding:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;border-bottom:1px solid #E5E7EB;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td style="padding:10px 0;color:#0B0B0E;border-bottom:1px solid #F1F5F9;">{{ $item->description }}</td>
                    <td align="center" style="padding:10px 0;color:#475569;border-bottom:1px solid #F1F5F9;">{{ $item->quantity }}</td>
                    <td align="right" style="padding:10px 0;color:#475569;border-bottom:1px solid #F1F5F9;">₦{{ number_format($item->unit_price, 2) }}</td>
                    <td align="right" style="padding:10px 0;color:#0B0B0E;font-weight:600;border-bottom:1px solid #F1F5F9;">₦{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Subtotal" value="₦{{ number_format($invoice->subtotal, 2) }}" />
            @if($invoice->tax_rate > 0)
                <x-email.row label="Tax ({{ number_format($invoice->tax_rate, 1) }}%)" value="₦{{ number_format($invoice->tax_amount, 2) }}" />
            @endif
            <x-email.row label="Total" value="₦{{ number_format($invoice->total, 2) }}" strong />
        </x-email.rows>
    </x-email.panel>

    @if($invoice->terms)
        <x-email.callout tone="neutral" title="Terms">
            {{ $invoice->terms }}
        </x-email.callout>
    @endif

    @if($paymentUrl)
        <x-email.button href="{{ $paymentUrl }}">Pay invoice now</x-email.button>
    @endif
</x-email.layout>
