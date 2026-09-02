<?php

namespace App\Mail;

use App\Models\User;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminBusinessCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public User $user)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('New Business Created: '.$this->user->name)
            ->view('emails.admin.business-created')
            ->with(['user' => $this->user]);
    }
}
