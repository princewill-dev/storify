<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorLoginAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Vendor $vendor;
    public string $ipAddress;
    public string $userAgent;

    public function __construct(Vendor $vendor, string $ipAddress, string $userAgent)
    {
        $this->vendor = $vendor;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    public function build(): self
    {
        return $this->subject('New login to your vendor account')
            ->view('emails.vendor.login-alert')
            ->with([
                'vendor' => $this->vendor,
                'ipAddress' => $this->ipAddress,
                'userAgent' => $this->userAgent,
            ]);
    }
}
