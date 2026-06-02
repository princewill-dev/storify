<?php

namespace App\Mail;

use App\Models\KycApplication;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminKycSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public KycApplication $application)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        $applicant = $this->application->user_id ? \App\Models\User::find($this->application->user_id) : null;
        return $this->subject('[KYC] New submission from ' . ($applicant?->name ?? 'vendor'))
            ->view('emails.admin.kyc-submitted')
            ->with([
                'application' => $this->application,
                'user' => $applicant,
            ]);
    }
}
