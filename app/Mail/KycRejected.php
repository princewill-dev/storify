<?php

namespace App\Mail;

use App\Models\KycApplication;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * WS-3 — the rejection notification legacy never sent.
 *
 * Legacy's reject action flashed "KYC application rejected and the business
 * owner notified" while queueing nothing (consolidated defect §24.1). This
 * mailable closes that gap and carries the reviewer's reason, which is the
 * only actionable part for the owner.
 */
class KycRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public KycApplication $application,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your KYC submission needs attention');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.kyc.rejected');
    }

    public function attachments(): array
    {
        return [];
    }
}
