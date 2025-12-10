<?php

namespace App\Mail;

use App\Models\Shop4meRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Shop4mePaymentReceivedUser extends Mailable
{
    use Queueable, SerializesModels;

    public Shop4meRequest $requestModel;

    public function __construct(Shop4meRequest $requestModel)
    {
        $this->requestModel = $requestModel;
    }

    public function build()
    {
        return $this->subject('Payment received for SHOP4ME list '.$this->requestModel->list_id)
            ->view('emails.shop4me.payment_user');
    }
}
