<?php

namespace App\Mail\Concerns;

use App\Support\EmailBrand;

/**
 * The platform's own letterhead, for the custody money alerts.
 *
 * Every mailable in this application builds the same array from `settings` and the
 * views all render the same header and footer from it; the older ones repeat the
 * dozen lines inline, which is fine for one-off business mail and is not for a
 * family of five alerts that differ only in their body. The values are read once,
 * at construction, so a mail built during a settings change describes one state of
 * the platform rather than two.
 *
 * The resolver is shared with `AppServiceProvider`, so the custody family gets the
 * same `public/logo.png` fallback as every other template.
 */
trait HasCompanyDetails
{
    /** @var array<string, string|null> */
    public array $company = [];

    protected function companyDetails(): array
    {
        return EmailBrand::resolve();
    }
}
