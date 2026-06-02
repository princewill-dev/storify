<?php

namespace App\Mail;

use App\Models\User;
use App\Models\KycApplication;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorKycSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public User $user, public KycApplication $application)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('We received your KYC submission')
            ->view('emails.vendor.kyc-submitted')
            ->with([
                'user' => $this->user,
                'application' => $this->application,
            ]);
    }
}
