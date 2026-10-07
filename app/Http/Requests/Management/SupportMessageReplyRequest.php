<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-33 — the support reply body.
 *
 * The controller's inline `reply` rule, moved verbatim.
 *
 * The tenant 403 (authorizeMessage) and the closed-conversation 422 stay in
 * the controller body: they are status codes and message strings whose order
 * is asserted. Extracting this rule moves validation ahead of them, so a
 * caller who is both unauthorised and malformed now answers 422 where it
 * answered 403 — the known, accepted consequence of the extraction across
 * this codebase. A valid payload from an unauthorised caller still gets 403,
 * and route-binding 404 still precedes both.
 */
class SupportMessageReplyRequest extends FormRequest
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
            'reply' => ['required', 'string', 'max:2000'],
        ];
    }
}
