<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * The letterhead every email is built from.
 *
 * Two places used to resolve this separately: `AppServiceProvider` for the
 * shared `$company` object and `HasCompanyDetails` for the custody family. The
 * second had no `public/logo.png` fallback, so the same platform could send
 * mail with a logo in one template and none in another.
 *
 * Safe to call before the settings table exists — money alerts are sent
 * synchronously from workers and must never fail because the letterhead could
 * not be read.
 */
final class EmailBrand
{
    /** @return array{name: string, logo: string, email: string, phone: ?string, address: ?string, branch_address: ?string, url: string} */
    public static function resolve(): array
    {
        try {
            $settings = Schema::hasTable('settings')
                ? Setting::query()->first()
                : null;
        } catch (\Throwable) {
            $settings = null;
        }

        return self::fromCompany($settings);
    }

    /**
     * Build the letterhead from the settings row or the cached `$company` object.
     *
     * @param  object|array<string, mixed>|null  $company
     * @return array{name: string, logo: string, email: string, phone: ?string, address: ?string, branch_address: ?string, url: string}
     */
    public static function fromCompany(object|array|null $company): array
    {
        $get = static function (string $key) use ($company): mixed {
            if (is_array($company)) {
                return $company[$key] ?? null;
            }

            if (is_object($company)) {
                return $company->{$key} ?? null;
            }

            return null;
        };

        $logoPath = $get('logo_path') ?: $get('company_logo_path');
        $logo = $get('logo');

        return [
            'name' => (string) ($get('name') ?: $get('company_name') ?: config('app.name')),
            'logo' => (string) ($logo ?: ($logoPath ? asset('storage/'.$logoPath) : asset('logo.png'))),
            'email' => (string) ($get('email') ?: $get('support_email') ?: config('mail.from.address')),
            'phone' => $get('phone') ?: $get('support_phone'),
            'address' => $get('address') ?: $get('company_address'),
            'branch_address' => $get('branch_address'),
            'url' => SpaUrls::home(),
        ];
    }
}
