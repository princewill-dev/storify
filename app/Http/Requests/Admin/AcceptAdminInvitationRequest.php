<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-10 (admin console) — accepting a platform-admin invitation.
 *
 * The invitee has no session yet and the route strips the admin group's auth
 * middleware, so there is nothing to authorize here: the invitation token is
 * the credential and the controller still resolves it (404) and checks the
 * invited status (409) with a valid payload, in that order.
 *
 * Both endpoints are public in the same sense, so no platform-admin guard
 * belongs here or in middleware — unlike the rest of the admin console.
 */
class AcceptAdminInvitationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
