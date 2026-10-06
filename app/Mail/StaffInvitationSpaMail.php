<?php

namespace App\Mail;

use App\Models\User;
use App\Support\SpaUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * WS-20 — invitation mail for the new management SPA.
 *
 * The original StaffInvitationMail still links staff to the legacy Blade
 * accept page (`management.staff.invitation.accept`). WS-20 could not edit
 * that shared class, so this sibling reuses the same template and points the
 * link at the SPA accept route instead. If the two are ever merged, keep the
 * SPA link.
 *
 * The SPA base URL comes from MANAGEMENT_SPA_URL; without it we derive the
 * platform convention (app.<main domain>) and fall back to the local Vite
 * dev server in local environments.
 */
class StaffInvitationSpaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $plainPassword = null,
    ) {}

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
                'acceptUrl' => $this->acceptUrl(),
                'plainPassword' => $this->plainPassword,
            ],
        );
    }

    public function acceptUrl(): string
    {
        return SpaUrls::management('/invitations/'.$this->user->invitation_token);
    }
}
