<?php

namespace App\Mail;

use App\Models\User;
use App\Models\KycApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KycApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public KycApplication $application,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your KYC has been approved — your stores can now go live');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.kyc.approved');
    }

    public function attachments(): array
    {
        return [];
    }
}
