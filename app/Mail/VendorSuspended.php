<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorSuspended extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $reason;
    public array $company;

    public function __construct(User $user, string $reason)
    {
        $this->user = $user;
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
        return $this->subject('Vendor Account Suspended')
            ->view('emails.vendor.suspended')
            ->with([
                'user' => $this->user,
                'reason' => $this->reason,
                'company' => (object) $this->company,
            ]);
    }
}
