<?php

namespace App\Http\Resources\Admin;

use App\Http\Requests\Admin\UpdateSettingsRequest;
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
            'greeting_modal_frequencies' => collect(UpdateSettingsRequest::GREETING_FREQUENCIES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }
}
