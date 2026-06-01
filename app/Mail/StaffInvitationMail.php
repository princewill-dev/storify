<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $plainPassword = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'ve been invited to join Storify',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.invitation',
            with: [
                'acceptUrl' => route('management.staff.invitation.accept', ['token' => $this->user->invitation_token]),
                'plainPassword' => $this->plainPassword,
            ],
        );
    }
}
