<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Queue\WithQueueConfig;

class OtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $otpCode,
        public int $expiryMinutes = 10
    ) {
        $this->initQueueConfig();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ' . config('app.name') . ' verification code: ' . $this->otpCode,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'otpCode' => $this->otpCode,
                'expiryMinutes' => $this->expiryMinutes,
                'appName' => config('app.name', 'Ecom'),
                'appUrl' => config('app.url'),
                'appDomain' => parse_url(config('app.url'), PHP_URL_HOST),
                'supportUrl' => config('app.url') . '/support',
                'companyAddress' => 'Your Company Address, City, State, ZIP',
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
