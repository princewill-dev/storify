<?php

namespace App\Mail;

use App\Models\Shop4meItem;
use App\Models\Shop4meItemResponse;
use App\Models\Shop4meRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Shop4meItemResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public Shop4meRequest $requestModel;
    public Shop4meItem $item;
    public Shop4meItemResponse $responseModel;

    public function __construct(Shop4meRequest $requestModel, Shop4meItem $item, Shop4meItemResponse $responseModel)
    {
        $this->requestModel = $requestModel;
        $this->item = $item;
        $this->responseModel = $responseModel;
    }

    public function build()
    {
        return $this->subject('Update to your SHOP4ME list '.$this->requestModel->list_id)
            ->view('emails.shop4me.item_response');
    }
}
