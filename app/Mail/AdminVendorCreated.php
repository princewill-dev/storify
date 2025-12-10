<?php

namespace App\Mail;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Queue\WithQueueConfig;

class AdminVendorCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public Vendor $vendor)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('New Vendor Created: '.$this->vendor->name)
            ->view('emails.admin.vendor-created')
            ->with(['vendor' => $this->vendor]);
    }
}
