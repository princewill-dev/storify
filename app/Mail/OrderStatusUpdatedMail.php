<?php

namespace App\Mail;

use App\Models\Order;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus
    ) {
        $this->initQueueConfig();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Status Update - ' . $this->order->order_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order.order-status-updated',
            with: [
                'order' => $this->order,
                'customer' => $this->order->customer,
                'oldStatus' => $this->oldStatus,
                'newStatus' => $this->newStatus,
                'appName' => config('app.name', 'Ecom'),
                'appUrl' => config('app.url'),
            ],
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
