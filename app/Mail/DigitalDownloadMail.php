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

class DigitalDownloadMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your download is ready — '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        $downloads = $this->order->digitalDownloads()
            ->with('product')
            ->get()
            ->filter(fn ($download) => $download->product !== null)
            ->map(fn ($download) => [
                'product_name' => $download->product->name,
                'url' => route('downloads.show', ['token' => $download->token]),
                'downloads_remaining' => $download->downloadsRemaining(),
                'expires_at' => $download->expires_at,
                'file_count' => $download->product->files->count(),
            ])
            ->values();

        return new Content(
            view: 'emails.order.digital-download',
            with: [
                'order' => $this->order,
                'customer' => $this->order->customer,
                'store' => $this->order->store,
                'downloads' => $downloads,
                'appName' => config('app.name'),
            ],
        );
    }
}
