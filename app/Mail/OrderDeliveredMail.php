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

class OrderDeliveredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Order $order
    ) {
        $this->initQueueConfig();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Order Has Been Delivered - ' . $this->order->order_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order.order-delivered',
            with: [
                'order' => $this->order,
                'customer' => $this->order->customer,
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
