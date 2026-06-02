<?php

namespace App\Mail;

use App\Models\Subscription;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialExpiryReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Subscription $subscription;
    public int $daysRemaining;

    public function __construct(Subscription $subscription, int $daysRemaining)
    {
        $this->subscription = $subscription;
        $this->daysRemaining = $daysRemaining;
    }

    public function envelope(): Envelope
    {
        $subject = $this->daysRemaining > 0
            ? "Your free trial expires in {$this->daysRemaining} day(s)"
            : "Your free trial expires today";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial_expiry_reminder');
    }

    public function attachments(): array
    {
        return [];
    }
}
