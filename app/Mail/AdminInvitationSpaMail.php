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
 * WS-10 — invitation mail for the new admin SPA.
 *
 * The shared `AdminInvitationMail` still links invitees to the legacy Blade
 * accept page (`admin.invitation.accept`), which WS-10 must not edit. This
 * sibling reuses the same template and points the link at the SPA accept
 * route (`/accept-invitation/:token`) the workstream ships, so the loop
 * invite -> email -> accept screen -> dashboard actually closes in-product.
 * If the two are ever merged, keep the SPA link.
 *
 * The SPA base URL comes from ADMIN_SPA_URL; without it we derive the platform
 * convention (admin.<main domain>) and fall back to the local Vite dev server
 * in local environments. Mirrors StaffInvitationSpaMail (WS-20).
 */
class AdminInvitationSpaMail extends Mailable
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
                'acceptUrl' => $this->acceptUrl(),
            ],
        );
    }

    public function acceptUrl(): string
    {
        return SpaUrls::admin('/accept-invitation/'.$this->user->invitation_token);
    }
}
