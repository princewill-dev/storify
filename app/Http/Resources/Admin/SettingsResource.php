<?php

namespace App\Http\Resources\Admin;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-02 (admin) — the platform settings payload.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them). The homepage store and the
 * default currency id are not columns on the singleton row as the response
 * needs them, so the controller reads them through SettingsRepository and
 * passes them in; this class only shapes.
 *
 * Effective values are reported, so a fresh install returns the same defaults
 * the legacy form displayed even before the first save.
 *
 * @property-read Setting|null $resource
 */
final class SettingsResource extends JsonResource
{
    public function __construct(
        ?Setting $settings,
        private readonly ?int $defaultCurrencyId,
        private readonly ?Store $mainStore,
    ) {
        parent::__construct($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Setting|null $settings */
        $settings = $this->resource;
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
            'main_store' => $this->mainStore ? [
                'id' => $this->mainStore->id,
                'store_id' => $this->mainStore->store_id,
                'name' => $this->mainStore->name,
                'status' => $this->mainStore->status,
            ] : null,
            'store_creation_limit' => $settings?->store_creation_limit ?? 5,
            'trial_enabled' => (bool) ($settings?->trial_enabled ?? true),
            'trial_days' => (int) ($settings?->trial_days ?? 7),
            'default_currency_id' => $this->defaultCurrencyId,
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

    private function fileUrl(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }
}
