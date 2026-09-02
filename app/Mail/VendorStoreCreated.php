<?php

namespace App\Mail;

use App\Models\Store;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorStoreCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public Store $store)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('Your Store is Live: '.$this->store->name)
            ->view('emails.business.store-created')
            ->with(['store' => $this->store]);
    }
}
