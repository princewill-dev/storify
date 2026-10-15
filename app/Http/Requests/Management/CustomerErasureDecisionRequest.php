<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-56 — the payload for answering a deletion request.
 *
 * One request for both answers. The difference between approving and rejecting
 * is the endpoint that was called, not what was sent, and a body that named the
 * action would let a client ask to approve at a URL that says reject.
 *
 * `reason` is required on both, including the approval. Both are rare and one
 * of them is irreversible, and what the audit row records is the only account
 * of why the answer was what it was — the customer is not e-mailed either way
 * (a business-framed message needs its own template and sending domain; see
 * CustomerMessageMail). An optional field here would be empty on exactly the
 * decisions that most need explaining.
 */
class CustomerErasureDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller: it is a 403 the controller
        // owns ahead of the irreversible write, not a validation gate.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
