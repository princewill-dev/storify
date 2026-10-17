<?php

namespace App\Http\Resources\Admin;

use App\Models\Setting;
use App\Models\Store;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
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
        $processorName = $this->processorName($settings?->system_payment_processor);

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
            'billing_gateway' => $settings?->billing_gateway ?? PlatformBillingGateway::DEFAULT_CODE,
            // The raw stored value, not `SystemProcessor::code()`. An admin
            // looking at a switch that is on but not working — a selected
            // processor this build cannot run — has to be able to see *what* is
            // selected, and `code()` would report null and look like "off".
            // Whether it can actually be run is reported separately, as the
            // problem string below.
            'system_payment_processor' => $settings?->system_payment_processor,
            // Three facts the console needs to render the switch without a
            // second request: what the selected processor is called, why it
            // cannot be used when it cannot, and what an admin may choose
            // between. Derived from the registry rather than listed in the SPA,
            // so a provider that gains or loses an on-behalf path moves in one
            // place — the registry — and every screen follows.
            'system_payment_processor_name' => $processorName,
            'system_payment_processor_problem' => SystemProcessor::unavailableReason(),
            'system_payment_processors' => $this->processors(),
            'platform_commission_percent' => (float) ($settings?->platform_commission_percent ?? 0),
            'platform_commission_max_kobo' => max(0, (int) ($settings?->platform_commission_max_kobo ?? 0)),
            // Deprecated, echoed as zero for one release: the flat fee was
            // replaced by the cap, and a stale admin bundle must not present an
            // inert stored value as something still being charged.
            'platform_commission_fixed_kobo' => 0,
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

    /**
     * The stored processor's display name — read from the registry by the
     * *stored* code, not through `SystemProcessor::name()`.
     *
     * `name()` returns null when the selected processor cannot be run, which is
     * right for a screen deciding what to render and wrong here: an admin
     * looking at a broken switch has to see which processor is selected, and
     * "no name" reads as "nothing selected". The reason it cannot be run is
     * reported beside it.
     */
    private function processorName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code);
    }

    /**
     * The processors an admin may choose between, as `code`/`name` pairs.
     *
     * An option the console cannot label is an option it cannot offer, so the
     * name is resolved here rather than in the SPA — the registry is the one
     * place a provider's display name is written down.
     *
     * @return array<int, array{code: string, name: string}>
     */
    private function processors(): array
    {
        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'name' => (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code),
            ],
            SystemProcessor::selectableCodes(),
        );
    }
}
