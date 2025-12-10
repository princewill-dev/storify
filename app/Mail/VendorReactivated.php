<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorReactivated extends Mailable
{
    use Queueable, SerializesModels;

    public Vendor $vendor;
    public string $reason;
    public array $company;

    public function __construct(Vendor $vendor, string $reason)
    {
        $this->vendor = $vendor;
        $this->reason = $reason;
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
        return $this->subject('Vendor Account Reactivated')
            ->view('emails.vendor.reactivated')
            ->with([
                'vendor' => $this->vendor,
                'reason' => $this->reason,
                'company' => (object) $this->company,
            ]);
    }
}
