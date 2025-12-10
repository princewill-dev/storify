<?php

namespace App\Mail;

use App\Models\Shop4meRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Shop4mePaymentReceivedAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public Shop4meRequest $requestModel;

    public function __construct(Shop4meRequest $requestModel)
    {
        $this->requestModel = $requestModel;
    }

    public function build()
    {
        return $this->subject('SHOP4ME payment made: '.$this->requestModel->list_id)
            ->view('emails.shop4me.payment_admin');
    }
}
