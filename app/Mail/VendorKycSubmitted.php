<?php

namespace App\Mail;

use App\Models\Vendor;
use App\Models\VendorKycApplication;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorKycSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public Vendor $vendor, public VendorKycApplication $application)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('We received your KYC submission')
            ->view('emails.vendor.kyc-submitted')
            ->with([
                'vendor' => $this->vendor,
                'application' => $this->application,
            ]);
    }
}
