<?php

namespace App\Mail;

use App\Models\User;
use App\Traits\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PasswordResetOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(
        public User $user,
        public string $otp
    ) {
        $this->applyQueueConfig();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Reset OTP',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-otp',
            with: [
                'user' => $this->user,
                'otp' => $this->otp,
            ],
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('email.password_reset_otp.failed', [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'error' => $exception->getMessage(),
        ]);
    }
}
