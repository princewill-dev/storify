<?php

namespace App\Mail;

use App\Models\Shop4meRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Shop4meListSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public Shop4meRequest $requestModel;

    public function __construct(Shop4meRequest $requestModel)
    {
        $this->requestModel = $requestModel;
    }

    public function build()
    {
        return $this->subject('New SHOP4ME list submitted: '.$this->requestModel->list_id)
            ->view('emails.shop4me.list_submitted');
    }
}
