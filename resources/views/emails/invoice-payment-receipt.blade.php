<x-email.layout
    title="Payment Received"
    preheader="We received your payment for invoice {{ $invoice->invoice_number }}."
    eyebrow="Receipt"
    heading="Payment received"
    subtitle="Thank you for your payment"
    badge="Paid"
    tone="success"
    :brand="$invoice->store ? ['name' => $invoice->store->name, 'logo' => $invoice->store->logoUrl(), 'email' => $invoice->store->support_email, 'phone' => $invoice->store->support_phone, 'address' => $invoice->store->address] : []"
    :powered-by="(bool) $invoice->store">

    <x-email.amount label="Amount paid">₦{{ number_format($transaction->amount, 2) }}</x-email.amount>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Invoice" :value="$invoice->invoice_number" strong />
            <x-email.row label="Reference" :value="$transaction->reference" mono />
            <x-email.row label="Date" :value="$transaction->created_at->format('M d, Y h:i A')" />
        </x-email.rows>
    </x-email.panel>

    <x-email.panel>
        <x-email.rows>
            <x-email.row label="Total invoice" value="₦{{ number_format($invoice->total, 2) }}" />
            <x-email.row label="Total paid" value="₦{{ number_format($invoice->amount_paid, 2) }}" />
            @if($invoice->remainingBalance() > 0)
                <x-email.row label="Remaining" value="₦{{ number_format($invoice->remainingBalance(), 2) }}" strong tone="warning" />
            @endif
        </x-email.rows>
    </x-email.panel>
</x-email.layout>
