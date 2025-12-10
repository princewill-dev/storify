<?php

namespace App\Mail;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Queue\WithQueueConfig;

class AdminStoreCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(public Store $store)
    {
        $this->initQueueConfig();
    }

    public function build(): self
    {
        return $this->subject('New Store Created: '.$this->store->name)
            ->view('emails.admin.store-created')
            ->with([
                'store' => $this->store,
                'vendor' => $this->store->vendor,
            ]);
    }
}
