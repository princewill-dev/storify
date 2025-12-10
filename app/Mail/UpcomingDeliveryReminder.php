<?php

namespace App\Mail;

use App\Models\FamilyPackDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UpcomingDeliveryReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $delivery;
    public $type; // 'pre_delivery', 'delivery_day', 'overdue'

    /**
     * Create a new message instance.
     */
    public function __construct(FamilyPackDelivery $delivery, string $type = 'pre_delivery')
    {
        $this->delivery = $delivery;
        $this->type = $type;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = match($this->type) {
            'pre_delivery' => 'Upcoming Delivery Reminder',
            'delivery_day' => 'Your Delivery is Today!',
            'overdue' => 'Payment Overdue - Delivery at Risk',
            default => 'Delivery Reminder'
        };

        return new Envelope(
            subject: $subject . ' - ' . $this->delivery->familyPackOrder->pack_code,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.family_pack.delivery_reminder',
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
