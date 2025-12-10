<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Shop4meOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $listId;
    public string $otp;

    public function __construct(string $listId, string $otp)
    {
        $this->listId = $listId;
        $this->otp = $otp;
    }

    public function build()
    {
        return $this->subject('Your SHOP4ME verification code')
            ->view('emails.shop4me.otp');
    }
}
