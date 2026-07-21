<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?string $paymentUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        $storeName = $this->invoice->store?->name ?? config('app.name');
        return new Envelope(
            subject: "Invoice {$this->invoice->invoice_number} from {$storeName}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice');
    }

    public function attachments(): array
    {
        return [];
    }
}
