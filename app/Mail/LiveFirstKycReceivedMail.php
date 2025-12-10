<?php

namespace App\Mail;

use App\Models\LiveFirstApplication;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LiveFirstKycReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public LiveFirstApplication $application
    ) {
        $this->initQueueConfig();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Live First Application Received - ' . $this->application->kyc_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.live-first.kyc-received',
            with: [
                'application' => $this->application,
                'customer' => $this->application->user,
                'kycId' => $this->application->kyc_id,
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
