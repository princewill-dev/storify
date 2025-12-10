<?php

namespace App\Mail;

use App\Models\FamilyPackOrder;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FamilyPackFinalizedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public FamilyPackOrder $familyPackOrder,
        public Order $order
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Family Pack ' . $this->familyPackOrder->pack_code . ' – First Delivery Ready for Payment',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.family_pack.finalized',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
