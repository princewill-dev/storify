<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming the two names signup did not ask for, and the phone number it did
 * not take either.
 *
 * Registration collects an email and a password; the business name, the owner's
 * name and the phone number are all derived or absent until this call. This is
 * the "Setup payment" card's form, and the reason it exists as its own endpoint
 * rather than a second use of `POST management/setup`: that route only creates,
 * and refuses with 409 the moment a business exists — which is precisely the
 * state every caller of this one is in.
 *
 * `name` and `owner_name` are required, and deliberately: arriving here with a
 * blank box is the point of the screen, and saving it unchanged would confirm
 * nothing. `phone` is optional — a business can be paid without one, and
 * demanding it here would be the form field the redesign removed from signup
 * arriving again by the back door.
 *
 * There is no `business_id`. The business is the caller's own, resolved from
 * the token, never named by the request.
 */
class UpdateBusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The business's name — what goes on a receipt and an invoice.
            'name' => ['required', 'string', 'max:255'],
            // The owner's own name, written to `users.name`. Softer than the
            // business's: it is a person's own name, and 255 is the column.
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
