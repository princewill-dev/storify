<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $company;

    public function __construct(
        public User $user,
        public string $temporaryPassword,
    ) {
        $s = Setting::first();
        $logoPath = $s?->company_logo_path;
        $this->company = [
            'name' => $s?->company_name ?? config('app.name'),
            'logo' => $logoPath ? asset('storage/'.$logoPath) : null,
            'support_email' => $s?->support_email ?? config('mail.from.address'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Storify password has been reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.user-password-reset',
            with: [
                'loginUrl' => route('management.auth.login'),
            ],
        );
    }
}
