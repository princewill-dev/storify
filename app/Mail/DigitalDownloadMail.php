<?php

namespace App\Mail;

use App\Models\Order;
use App\Queue\WithQueueConfig;
use App\Support\SpaUrls;
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
        // The storefront SPA owns the download page now, so the link points at
        // the store's own subdomain rather than an API Blade route.
        $storeSlug = (string) ($this->order->store?->slug ?? '');

        $downloads = $this->order->digitalDownloads()
            ->with('product')
            ->get()
            ->filter(fn ($download) => $download->product !== null)
            ->map(fn ($download) => [
                'product_name' => $download->product->name,
                'url' => SpaUrls::storefront($storeSlug, '/download/'.$download->token),
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
