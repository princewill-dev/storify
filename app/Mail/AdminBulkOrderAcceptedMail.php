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

class AdminBulkOrderAcceptedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public BulkOrder $bulkOrder)
    {
        $this->initQueueConfig();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Customer Accepted Bulk Order - ' . $this->bulkOrder->bulk_code,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bulk.admin-order-accepted',
            with: [
                'bulkOrder' => $this->bulkOrder,
                'customer' => $this->bulkOrder->customer,
                'customerName' => $this->bulkOrder->customer->full_name,
                'store' => $this->bulkOrder->store,
                'items' => $this->bulkOrder->items,
                'company' => (object) [
                    'name' => config('app.name'),
                    'logo' => config('app.logo'),
                    'email' => config('mail.from.address'),
                    'phone' => config('app.phone', ''),
                    'address' => config('app.address', ''),
                    'branch_address' => config('app.branch_address', ''),
                ],
                'appUrl' => config('app.url'),
                'adminUrl' => config('app.url') . '/superadmin/bulk-orders/' . $this->bulkOrder->id,
            ],
        );
    }
}
