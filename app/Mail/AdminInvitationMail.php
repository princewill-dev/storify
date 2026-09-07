<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to join the Storify admin team',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.invitation',
            with: [
                'acceptUrl' => route('admin.invitation.accept', ['token' => $this->user->invitation_token]),
            ],
        );
    }
}
