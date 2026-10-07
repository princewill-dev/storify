<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-04 — the optional reason shared by store suspend and activate, moved
 * verbatim from the controller's inline rules (`nullable`, string, max 2000).
 * No default lives here: the controller applies its own fallback string when
 * the reason is omitted, exactly as the inline `$data['reason'] ?? ...` did.
 *
 * The tenant guard stays in the controller on purpose: it is a 403 the
 * controller owns ahead of the write, not a validation gate, and it must not
 * move into middleware or this class's authorize(). The same goes for the
 * status/KYC refusals — they are 422s whose message strings are part of the
 * controller's HTTP shape.
 */
class StoreLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
