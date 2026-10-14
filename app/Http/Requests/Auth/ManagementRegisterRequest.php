<?php

namespace App\Http\Requests\Auth;

use App\Support\Identity\DerivedName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Management-app registration: the business-owner account payload.
 *
 * The email is checked against both the `users` and the `customers` tables —
 * one address may not exist on either side of the platform. A concurrent
 * insert can still race these rules; the unique-key collision that results is
 * caught in `ManagementAuthService::register()`, which the controller maps to
 * its 422.
 *
 * ## `name` and `phone` are optional, and the next release may remove them
 *
 * Signup asks for an email and a password. A name was never needed at that
 * moment — nothing in the account is usable until a business and a plan exist,
 * and the name appears nowhere the person can see — and a phone number is
 * collected at "Setup payment", where it is attached to a business and a real
 * purpose. `users.name` is NOT NULL, so the account still gets one: it is
 * derived from the email by {@see DerivedName} and
 * corrected on the payment settings screen.
 *
 * They are **accepted but not required** rather than removed, for one release,
 * so that a management SPA built before this change — which still sends all
 * four fields — keeps working against the new API instead of 422-ing on a
 * screen nobody has deployed yet. Removing the rules outright is the step
 * after that, once the SPA that sends them is the only one in the wild.
 */
class ManagementRegisterRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
