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

class CustomerOrderStatusUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus
    ) {
        $this->initQueueConfig();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Status Update - ' . $this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer.order-status-updated',
            with: [
                'order' => $this->order,
                'oldStatus' => $this->oldStatus,
                'newStatus' => $this->newStatus,
                'customer' => $this->order->customer,
                'items' => $this->order->items,
                'company' => (object) [
                    'name' => config('app.name'),
                    'logo' => config('app.logo'),
                    'email' => config('mail.from.address'),
                    'phone' => config('app.phone', ''),
                    'address' => config('app.address', ''),
                    'branch_address' => config('app.branch_address', ''),
                ],
                'appUrl' => config('app.url'),
                'supportUrl' => config('app.url') . '/support',
            ],
        );
    }
}
