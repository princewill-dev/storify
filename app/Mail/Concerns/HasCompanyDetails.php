<?php

namespace App\Mail\Concerns;

use App\Models\Setting;

/**
 * The platform's own letterhead, for the custody money alerts.
 *
 * Every mailable in this application builds the same array from `settings` and the
 * views all render the same header and footer from it; the older ones repeat the
 * dozen lines inline, which is fine for one-off business mail and is not for a
 * family of five alerts that differ only in their body. The values are read once,
 * at construction, so a mail built during a settings change describes one state of
 * the platform rather than two.
 */
trait HasCompanyDetails
{
    /** @var array<string, string|null> */
    public array $company = [];

    protected function companyDetails(): array
    {
        $settings = Setting::query()->first();

        return [
            'logo' => $settings?->company_logo_path ? asset('storage/'.$settings->company_logo_path) : null,
            'name' => $settings->company_name ?? config('app.name'),
            'email' => $settings->support_email ?? null,
            'phone' => $settings->support_phone ?? null,
            'address' => $settings->company_address ?? null,
            'branch_address' => $settings->branch_address ?? null,
        ];
    }
}
