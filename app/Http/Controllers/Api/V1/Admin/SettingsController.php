<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\SettingsUpdated;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WS-02 (admin) — platform settings & branding.
 *
 * The `settings` table is a singleton row. Four caches read it at runtime
 * (`company_settings`, `admin_main_store`, `home_main_store` and
 * `home_api_company`), so every save must bust them or the storefront and the
 * admin shell keep serving stale branding.
 *
 * Deliberate differences from the legacy screen (admin-dashboard-config audit,
 * corrections C2/C5):
 *  - `og_type` and `greeting_modal_frequency` are enum-validated instead of
 *    stored as arbitrary strings.
 *  - the write-only `api_keys` vault is not ported; an ordinary save can no
 *    longer null the column, and no request can write it through this API.
 *  - the favicon accepts ICO again: legacy paired the `image` rule with
 *    `mimes:...,ico`, so a real .ico upload always failed validation.
 *  - validation failures return per-field errors, which the legacy form never
 *    rendered.
 *
 * Every save writes an ActivityLog row carrying the changed key *names* only —
 * never values — mirroring the legacy "changed keys, values redacted" diff.
 *
 * Like every other platform-office screen, the route permission is not enough
 * on its own: a business's in-business "Super Admin" role bundles the admin.*
 * names, so the platform-role guard is what keeps a leaked admin-audience
 * token from a business account out of the platform branding.
 */
class SettingsController extends ApiController
{
    use EnsuresPlatformAdmin;

    /** Open Graph types the SPA may choose from (legacy rendered exactly these). */
    public const OG_TYPES = ['website', 'article', 'product'];

    /** Greeting-modal frequencies; keys are stored, values are display labels. */
    public const GREETING_FREQUENCIES = [
        'never' => 'Never',
        'always' => 'Always (Every Page Load)',
        'once_per_session' => 'Once Per Session',
        'once_per_day' => 'Once Per Day',
        'once_per_week' => 'Once Per Week',
        'once_per_month' => 'Once Per Month',
    ];

    /**
     * Upload input name => settings column. Validation limits live in
     * update()'s rule list.
     */
    private const UPLOADS = [
        'company_logo' => 'company_logo_path',
        'company_favicon' => 'company_favicon_path',
        'company_certificate' => 'company_certificate_path',
        'og_image' => 'og_image_path',
    ];

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

    public function show(): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $settings = Setting::query()->first();

        return $this->ok([
            'settings' => $this->settingsPayload($settings),
            'stores' => $this->storeOptions($settings?->main_store_id),
            'currencies' => $this->currencyOptions(),
            'options' => [
                'og_types' => self::OG_TYPES,
                'greeting_modal_frequencies' => collect(self::GREETING_FREQUENCIES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_description' => ['nullable', 'string', 'max:2000'],
            'company_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'company_favicon' => ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'],
            'company_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'og_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:2000'],
            'branch_address' => ['nullable', 'string', 'max:2000'],
            // The homepage store must exist and must not be soft-deleted —
            // the legacy rule accepted deleted rows the picker never showed.
            'main_store_id' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->whereNot('status', Store::STATUS_DELETED),
            ],
            'default_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'store_creation_limit' => ['nullable', 'integer', 'min:1'],
            'trial_enabled' => ['sometimes', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'greeting_modal_enabled' => ['sometimes', 'boolean'],
            'greeting_modal_frequency' => ['nullable', Rule::in(array_keys(self::GREETING_FREQUENCIES))],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:2000'],
            'og_url' => ['nullable', 'url', 'max:255'],
            'og_type' => ['nullable', Rule::in(self::OG_TYPES)],
        ]);

        [$settings, $changed] = DB::transaction(function () use ($request, $validated) {
            $settings = Setting::query()->first() ?? new Setting;
            $before = $settings->exists ? $settings->toArray() : [];
            $beforeCurrencyId = $this->defaultCurrencyId();

            $data = [
                'company_name' => $validated['company_name'] ?? null,
                'company_description' => $validated['company_description'] ?? null,
                'support_email' => $validated['support_email'] ?? null,
                'support_phone' => $validated['support_phone'] ?? null,
                'company_address' => $validated['company_address'] ?? null,
                'branch_address' => $validated['branch_address'] ?? null,
                'main_store_id' => $validated['main_store_id'] ?? null,
                'store_creation_limit' => $validated['store_creation_limit'] ?? 5,
                'trial_enabled' => $request->boolean('trial_enabled'),
                'trial_days' => (int) ($validated['trial_days'] ?? 7),
                'og_title' => $validated['og_title'] ?? null,
                'og_description' => $validated['og_description'] ?? null,
                'og_url' => $validated['og_url'] ?? null,
                'og_type' => $validated['og_type'] ?? 'website',
                'greeting_modal_enabled' => $request->boolean('greeting_modal_enabled'),
                'greeting_modal_frequency' => $validated['greeting_modal_frequency'] ?? 'never',
            ];

            foreach (self::UPLOADS as $input => $column) {
                if ($request->hasFile($input)) {
                    $this->deleteStoredFile($settings->{$column});
                    $data[$column] = $request->file($input)->store('company', 'public');
                }
            }

            $settings->fill($data)->save();

            // One default currency platform-wide: clear every flag, then set the
            // chosen one — both inside this transaction.
            if (! empty($validated['default_currency_id'])) {
                Currency::query()->where('is_default', true)->update(['is_default' => false]);
                Currency::query()->where('id', $validated['default_currency_id'])->update(['is_default' => true]);
            }

            $afterCurrencyId = $this->defaultCurrencyId();

            return [
                $settings,
                $this->changedKeys($before, $settings->toArray(), $beforeCurrencyId, $afterCurrencyId),
            ];
        });

        $this->bustCaches($changed);
        $this->recordAudit($changed);
        $this->notifySuperadmin($changed);

        return $this->ok([
            'settings' => $this->settingsPayload($settings->fresh()),
            'changed_keys' => $changed,
        ], 'Settings updated.');
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

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(?Setting $settings): array
    {
        $mainStore = $settings?->main_store_id ? Store::find($settings->main_store_id) : null;
        $certificatePath = $settings?->company_certificate_path;

        return [
            'company_name' => $settings?->company_name,
            'company_description' => $settings?->company_description,
            'company_logo_path' => $settings?->company_logo_path,
            'company_logo_url' => $this->fileUrl($settings?->company_logo_path),
            'company_favicon_path' => $settings?->company_favicon_path,
            'company_favicon_url' => $this->fileUrl($settings?->company_favicon_path),
            'company_certificate_path' => $certificatePath,
            'company_certificate_url' => $this->fileUrl($certificatePath),
            'company_certificate_is_pdf' => $certificatePath
                ? str_ends_with(strtolower($certificatePath), '.pdf')
                : false,
            'support_email' => $settings?->support_email,
            'support_phone' => $settings?->support_phone,
            'company_address' => $settings?->company_address,
            'branch_address' => $settings?->branch_address,
            'main_store_id' => $settings?->main_store_id,
            'main_store' => $mainStore ? [
                'id' => $mainStore->id,
                'store_id' => $mainStore->store_id,
                'name' => $mainStore->name,
                'status' => $mainStore->status,
            ] : null,
            // Effective values, so a fresh install returns the same defaults the
            // legacy form displayed even before the first save.
            'store_creation_limit' => $settings?->store_creation_limit ?? 5,
            'trial_enabled' => (bool) ($settings?->trial_enabled ?? true),
            'trial_days' => (int) ($settings?->trial_days ?? 7),
            'default_currency_id' => $this->defaultCurrencyId(),
            'greeting_modal_enabled' => (bool) ($settings?->greeting_modal_enabled ?? false),
            'greeting_modal_frequency' => $settings?->greeting_modal_frequency ?? 'never',
            'og_title' => $settings?->og_title,
            'og_description' => $settings?->og_description,
            'og_image_path' => $settings?->og_image_path,
            'og_image_url' => $this->fileUrl($settings?->og_image_path),
            'og_url' => $settings?->og_url ?? url('/'),
            'og_type' => $settings?->og_type ?? 'website',
            'updated_at' => $settings?->updated_at?->toISOString(),
        ];
    }

    /**
     * Non-deleted stores for the homepage picker. If the saved homepage store
     * was deleted after selection it is kept in the list (flagged by its
     * status) so the form shows what is actually stored instead of blanking.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeOptions(?int $includeStoreId = null): array
    {
        return Store::query()
            ->where(function ($query) use ($includeStoreId) {
                $query->where('status', '!=', Store::STATUS_DELETED)
                    ->when($includeStoreId, fn ($inner) => $inner->orWhere('id', $includeStoreId));
            })
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'status'])
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $store->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currencyOptions(): array
    {
        return Currency::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'symbol', 'is_default'])
            ->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'is_default' => (bool) $currency->is_default,
            ])
            ->values()
            ->all();
    }

    private function fileUrl(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }

    /**
     * The single currency flagged `is_default`, as an int for the JSON payload.
     */
    private function defaultCurrencyId(): ?int
    {
        $id = Currency::query()->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }
}
