<?php

namespace App\Mail;

use App\Models\VendorKycApplication;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminKycSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public VendorKycApplication $application)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('[KYC] New submission from ' . ($this->application->vendor?->name ?? 'vendor'))
            ->view('emails.admin.kyc-submitted')
            ->with([
                'application' => $this->application,
                'vendor' => $this->application->vendor,
            ]);
    }
}
