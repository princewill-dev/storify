<?php

namespace App\Mail;

use App\Models\Subscription;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialExpiredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Subscription $subscription;

    public function __construct(Subscription $subscription)
    {
        $this->subscription = $subscription;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your free trial has expired — store is now offline',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trial_expired');
    }

    public function attachments(): array
    {
        return [];
    }
}
