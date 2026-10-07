<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepting a staff invitation (public endpoint).
 *
 * The invitee has no session yet and the route carries no auth middleware, so
 * the invitation token is the credential and there is nothing to authorize
 * here. The controller still resolves the token and answers with its single
 * 404 for an unknown or no-longer-invited account: with a valid payload that
 * order is unchanged. Validation now runs during parameter resolution, before
 * the controller body, so an invalid token plus a malformed payload answers
 * 422 instead of 404 — the accepted FormRequest extraction consequence.
 *
 * `name` is nullable on purpose: accepting without a name keeps whatever name
 * the invitation carried.
 */
class AcceptStaffInvitationRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
