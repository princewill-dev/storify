<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Queue\WithQueueConfig;

class AdminVendorCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public User $user)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('New Vendor Created: '.$this->user->name)
            ->view('emails.admin.user-created')
            ->with(['user' => $this->user]);
    }
}
