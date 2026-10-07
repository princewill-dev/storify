<?php

namespace App\Http\Requests\Admin;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * WS-02 (admin) — the platform settings save payload.
 *
 * One screen saves every field, so one rule set covers the whole form.
 * Deliberate differences from the legacy screen (admin-dashboard-config audit,
 * corrections C2/C5):
 *  - `og_type` and `greeting_modal_frequency` are enum-validated instead of
 *    stored as arbitrary strings.
 *  - the write-only `api_keys` vault is not ported: it is absent from this
 *    rule set, so an ordinary save can no longer null the column, and no
 *    request can write it through this API.
 *  - the favicon accepts ICO again: legacy paired the `image` rule with
 *    `mimes:...,ico`, so a real .ico upload always failed validation.
 *  - validation failures return per-field errors, which the legacy form never
 *    rendered.
 *
 * The option lists live here, next to the rules that validate them, so the
 * SPA pickers (`SettingsOptionsResource`) and the validator can never drift
 * apart.
 */
class UpdateSettingsRequest extends FormRequest
{
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
     * Upload input name => settings column. Validation limits live in rules()
     * below.
     */
    public const UPLOADS = [
        'company_logo' => 'company_logo_path',
        'company_favicon' => 'company_favicon_path',
        'company_certificate' => 'company_certificate_path',
        'og_image' => 'og_image_path',
    ];

    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
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
        ];
    }

    /**
     * The replacement uploads, keyed by the settings column each one
     * overwrites. The column map (and therefore the store order) is
     * {@see self::UPLOADS}; the service deletes the superseded file and stores
     * the replacement inside the save transaction.
     *
     * @return array<string, UploadedFile>
     */
    public function uploadedFiles(): array
    {
        $uploads = [];

        foreach (self::UPLOADS as $input => $column) {
            if ($this->hasFile($input)) {
                $uploads[$column] = $this->file($input);
            }
        }

        return $uploads;
    }
}
