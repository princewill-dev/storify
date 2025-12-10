<?php

namespace App\Mail;

use App\Models\FamilyPackOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FamilyPackRequestReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $familyPackOrder;

    /**
     * Create a new message instance.
     */
    public function __construct(FamilyPackOrder $familyPackOrder)
    {
        $this->familyPackOrder = $familyPackOrder;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Family Pack Request Received - ' . $this->familyPackOrder->pack_code,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.family_pack.request_received',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
