<?php

namespace App\Mail;

use App\Models\Store;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Queue\WithQueueConfig;

class VendorStoreReactivated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public array $company;

    public function __construct(public Store $store, public string $reason)
    {
        $this->initQueueConfig();
        $s = Setting::first();
        $this->company = [
            'logo' => $s?->company_logo_path ? asset('storage/' . $s->company_logo_path) : null,
            'name' => $s->company_name ?? config('app.name'),
            'email' => $s->support_email ?? null,
            'phone' => $s->support_phone ?? null,
            'address' => $s->company_address ?? null,
            'branch_address' => $s->branch_address ?? null,
        ];
    }

    public function build(): self
    {
        return $this->subject('Store Reactivated: ' . $this->store->name)
            ->view('emails.vendor.store-reactivated')
            ->with([
                'store' => $this->store,
                'reason' => $this->reason,
                'company' => (object) $this->company,
            ]);
    }
}
