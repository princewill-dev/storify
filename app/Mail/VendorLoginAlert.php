<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorLoginAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $ipAddress;
    public string $userAgent;

    public function __construct(User $user, string $ipAddress, string $userAgent)
    {
        $this->user = $user;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    public function build(): self
    {
        return $this->subject('New login to your vendor account')
            ->view('emails.vendor.login-alert')
            ->with([
                'user' => $this->user,
                'ipAddress' => $this->ipAddress,
                'userAgent' => $this->userAgent,
            ]);
    }
}
