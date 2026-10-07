<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Http\Resources\Admin\CurrencyOptionResource;
use App\Http\Resources\Admin\SettingsOptionsResource;
use App\Http\Resources\Admin\SettingsResource;
use App\Http\Resources\Admin\SettingsStoreOptionResource;
use App\Models\Setting;
use App\Repositories\Admin\SettingsRepository;
use App\Services\Admin\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * WS-02 (admin) — platform settings & branding.
 *
 * The `settings` table is a singleton row. Four caches read it at runtime
 * (`company_settings`, `admin_main_store`, `home_main_store` and
 * `home_api_company`), so every save must bust them or the storefront and the
 * admin shell keep serving stale branding.
 *
 * The deliberate differences from the legacy screen (admin-dashboard-config
 * audit, corrections C2/C5) live with the rules that encode them in
 * {@see UpdateSettingsRequest}. The save workflow — one transaction spanning
 * the settings row, the upload replacement and the `currencies.is_default`
 * flip, then cache busting, the changed-keys-only audit row and the change
 * mail, in that order — is in SettingsService; the reads are in
 * `SettingsRepository` and the response shapes in the Admin resources.
 *
 * Like every other platform-office screen, the route permission is not enough
 * on its own: a business's in-business "Super Admin" role bundles the admin.*
 * names, so the platform-role guard is what keeps a leaked admin-audience
 * token from a business account out of the platform branding.
 *
 * The controller keeps the HTTP shape only — status codes, the message string
 * and the envelope.
 */
class SettingsController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly SettingsService $service,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $settings = $this->repository->current();

        return $this->ok([
            'settings' => $this->settingsPayload($settings),
            'stores' => SettingsStoreOptionResource::collection(
                $this->repository->storeOptions($settings?->main_store_id)
            )->resolve(),
            'currencies' => CurrencyOptionResource::collection($this->repository->currencyOptions())->resolve(),
            'options' => (new SettingsOptionsResource(null))->resolve(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The two checkbox flags are normalised off the raw request (absent
        // means false), the way this save has always read them.
        $data = array_merge($request->validated(), [
            'trial_enabled' => $request->boolean('trial_enabled'),
            'greeting_modal_enabled' => $request->boolean('greeting_modal_enabled'),
        ]);

        [$settings, $changed] = $this->service->update($data, $request->uploadedFiles());

        return $this->ok([
            'settings' => $this->settingsPayload($settings->fresh()),
            'changed_keys' => $changed,
        ], 'Settings updated.');
    }

    /**
     * The settings payload, with the two values read outside the singleton
     * row: the platform's default currency and the homepage store (which may
     * have been deleted since it was saved — the payload still shows it).
     *
     * @return array<string, mixed>
     */
    private function settingsPayload(?Setting $settings): array
    {
        return SettingsResource::make(
            $settings,
            $this->repository->defaultCurrencyId(),
            $settings?->main_store_id ? $this->repository->findStore($settings->main_store_id) : null,
        )->resolve();
    }
}
