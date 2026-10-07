<?php

namespace App\Services\Admin;

use App\Mail\SettingsUpdated;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\Admin\SettingsRepository;
use App\Services\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * WS-02 (admin) — the platform-settings save workflow.
 *
 * One singleton row and the `currencies.is_default` flip (a second table)
 * share one transaction; the upload replacement runs inside it, in the order
 * this screen has always used the file lifecycle: the superseded file is
 * deleted just before the replacement is stored. After the transaction
 * commits, in this exact sequence: the branding caches are busted, the audit
 * row is written (changed key *names* only — never values) and the change mail
 * is queued to the first superadmin. Those three must stay after the commit
 * and in that order.
 *
 * The `settings` table is a singleton row. Four caches read it at runtime
 * (`company_settings`, `admin_main_store`, `home_main_store` and
 * `home_api_company`), so every save must bust them or the storefront and the
 * admin shell keep serving stale branding; a changed homepage store also
 * invalidates the suggested-products cache.
 *
 * Queuing and file-delete failures must never fail the save (both are logged
 * and swallowed). The reads live in SettingsRepository and the HTTP shape in
 * SettingsController.
 */
final class SettingsService
{
    /**
     * Columns compared for the audit diff. File path columns are included but
     * only their key names ever reach the log.
     */
    private const AUDITED_COLUMNS = [
        'company_name',
        'company_description',
        'company_logo_path',
        'company_favicon_path',
        'company_certificate_path',
        'support_email',
        'support_phone',
        'company_address',
        'branch_address',
        'main_store_id',
        'store_creation_limit',
        'trial_enabled',
        'trial_days',
        'og_title',
        'og_description',
        'og_image_path',
        'og_url',
        'og_type',
        'greeting_modal_enabled',
        'greeting_modal_frequency',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Persist one save of the whole settings screen.
     *
     * @param  array<string, mixed>  $data  the validated payload, with the two
     *                                      boolean flags already normalised
     *                                      from the request
     * @param  array<string, UploadedFile>  $uploads  settings column => replacement file
     * @return array{0: Setting, 1: array<int, string>} the saved row and the changed key names
     */
    public function update(array $data, array $uploads): array
    {
        [$settings, $changed] = DB::transaction(function () use ($data, $uploads) {
            $settings = $this->settings->current() ?? new Setting;
            $before = $settings->exists ? $settings->toArray() : [];
            $beforeCurrencyId = $this->settings->defaultCurrencyId();

            $attributes = [
                'company_name' => $data['company_name'] ?? null,
                'company_description' => $data['company_description'] ?? null,
                'support_email' => $data['support_email'] ?? null,
                'support_phone' => $data['support_phone'] ?? null,
                'company_address' => $data['company_address'] ?? null,
                'branch_address' => $data['branch_address'] ?? null,
                'main_store_id' => $data['main_store_id'] ?? null,
                'store_creation_limit' => $data['store_creation_limit'] ?? 5,
                'trial_enabled' => (bool) $data['trial_enabled'],
                'trial_days' => (int) ($data['trial_days'] ?? 7),
                'og_title' => $data['og_title'] ?? null,
                'og_description' => $data['og_description'] ?? null,
                'og_url' => $data['og_url'] ?? null,
                'og_type' => $data['og_type'] ?? 'website',
                'greeting_modal_enabled' => (bool) $data['greeting_modal_enabled'],
                'greeting_modal_frequency' => $data['greeting_modal_frequency'] ?? 'never',
            ];

            foreach ($uploads as $column => $file) {
                $this->deleteStoredFile($settings->{$column});
                $attributes[$column] = $file->store('company', 'public');
            }

            $settings->fill($attributes)->save();

            // One default currency platform-wide: clear every flag, then set the
            // chosen one — both inside this transaction.
            if (! empty($data['default_currency_id'])) {
                Currency::query()->where('is_default', true)->update(['is_default' => false]);
                Currency::query()->where('id', $data['default_currency_id'])->update(['is_default' => true]);
            }

            $afterCurrencyId = $this->settings->defaultCurrencyId();

            return [
                $settings,
                $this->changedKeys($before, $settings->toArray(), $beforeCurrencyId, $afterCurrencyId),
            ];
        });

        $this->bustCaches($changed);
        $this->recordAudit($changed);
        $this->notifySuperadmin($changed);

        return [$settings, $changed];
    }

    /**
     * @return array<int, string>
     */
    private function changedKeys(array $before, array $after, ?int $beforeCurrencyId, ?int $afterCurrencyId): array
    {
        $changed = [];

        foreach (self::AUDITED_COLUMNS as $column) {
            if (($before[$column] ?? null) != ($after[$column] ?? null)) {
                $changed[] = $column;
            }
        }

        if ($beforeCurrencyId !== $afterCurrencyId) {
            $changed[] = 'default_currency_id';
        }

        return $changed;
    }

    /**
     * @param  array<int, string>  $changed
     */
    private function bustCaches(array $changed): void
    {
        Cache::forget('company_settings');
        Cache::forget('home_api_company');
        Cache::forget('admin_main_store');

        if (in_array('main_store_id', $changed, true)) {
            Cache::forget('home_main_store');
            Cache::forget('search_suggested_products');
        }
    }

    /**
     * Audit the save with key names only — values (emails, addresses, paths)
     * never reach the log.
     *
     * @param  array<int, string>  $changed
     */
    private function recordAudit(array $changed): void
    {
        ActivityLogger::log('settings_updated', 'Platform settings updated.', [
            'changed_keys' => $changed,
        ]);

        Log::info('api.admin.settings_updated', [
            'user_id' => auth()->id(),
            'changed_keys' => $changed,
        ]);
    }

    /**
     * Queue the change notification to the first superadmin (legacy target).
     * Queuing failures must never fail the save.
     *
     * @param  array<int, string>  $changed
     */
    private function notifySuperadmin(array $changed): void
    {
        if ($changed === []) {
            return;
        }

        try {
            $superadmin = User::query()
                ->where('role', User::ROLE_SUPERADMIN)
                ->orderBy('id')
                ->first();

            if ($superadmin) {
                // Keyed list keeps the mailable's `key => diff` rendering (the
                // legacy mail printed "Company Name: Changed" per field).
                Mail::to($superadmin->email)->queue(new SettingsUpdated(array_fill_keys($changed, true)));
            }
        } catch (\Throwable $e) {
            Log::warning('api.admin.settings_updated_mail_failed', ['error' => $e->getMessage()]);
        }
    }

    private function deleteStoredFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('api.admin.settings_file_delete_failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
