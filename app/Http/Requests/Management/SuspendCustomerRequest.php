<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The suspension reason payload.
 *
 * The rule is the controller's inline set, moved verbatim: this base endpoint
 * accepts an optional reason for shape only and does not store it (the WS-19
 * parity controller persists it on the activity timeline).
 */
class SuspendCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: it is a 403 the
        // controller owns ahead of the write, not a validation gate, and it
        // must not move into middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
