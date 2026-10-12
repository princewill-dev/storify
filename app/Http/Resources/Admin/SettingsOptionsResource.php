<?php

namespace App\Http\Resources\Admin;

use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-02 (admin) — the settings form dropdowns.
 *
 * The lists live on {@see UpdateSettingsRequest}, next to the rules that
 * validate them, so the SPA's selectable values and the validator can never
 * drift apart; this resource only shapes them (and turns the frequency map
 * into the `value`/`label` pairs the SPA renders).
 */
final class SettingsOptionsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'og_types' => UpdateSettingsRequest::OG_TYPES,
            // The processors the platform can take every business's money
            // through. Same source as the validation rule above, so the picker
            // and the validator cannot drift.
            'system_payment_processors' => collect(SystemProcessor::selectableCodes())
                ->map(fn (string $code) => [
                    'value' => $code,
                    'label' => (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code),
                ])
                ->values()
                ->all(),
            // Non-null only when a processor *is* selected and this build cannot
            // run it: the one state the switch can be in where nothing works and
            // the value alone does not explain why.
            'system_processor_problem' => SystemProcessor::unavailableReason(),
            'greeting_modal_frequencies' => collect(UpdateSettingsRequest::GREETING_FREQUENCIES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }
}
