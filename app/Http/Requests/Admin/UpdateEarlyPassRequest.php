<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 (admin console) — early-pass edit payload.
 *
 * Only the description and the usage cap are editable here. The code is
 * immutable after creation; its refusal deliberately stays in the controller
 * beside the platform-admin guard — it consults the route-bound row and an
 * unauthorised caller sending a changed code must still be refused 403 first.
 * These two rules therefore run during parameter resolution, ahead of the
 * controller body — the same extraction consequence the rest of the console
 * already carries.
 */
final class UpdateEarlyPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // not move into FormRequest::authorize() or middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
