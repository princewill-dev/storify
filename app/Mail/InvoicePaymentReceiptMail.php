<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoicePaymentReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public Transaction $transaction,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Receipt — Invoice {$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice-payment-receipt');
    }

    public function attachments(): array
    {
        return [];
    }
}
