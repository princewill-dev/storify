<?php

namespace App\Mail;

use App\Models\BulkOrder;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BulkOrderUnderReviewMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public BulkOrder $bulkOrder)
    {
        $this->initQueueConfig();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Bulk Order is Under Review - ' . $this->bulkOrder->bulk_code,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bulk.order-under-review',
            with: [
                'bulkOrder' => $this->bulkOrder,
                'customer' => $this->bulkOrder->customer,
                'store' => $this->bulkOrder->store,
                'items' => $this->bulkOrder->items,
                'deliveryAddress' => $this->bulkOrder->deliveryAddress,
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
