<?php

namespace App\Http\Requests\Auth;

use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Storefront registration (POST storefront/{store}/auth/register, and the
 * transitional tenant-less path).
 *
 * A customer account belongs to one business, so the email has to be free
 * *here* and nowhere else. The rule used to be global — one address could not
 * be a shopper at two stores — which contradicted the schema, where the unique
 * index is `(business_id, email)`, and made a shopper's second store refuse
 * them for a reason that had nothing to do with that store.
 *
 * The unique rule ignores rows that were never verified, because those are
 * claimable rather than taken: an account this person started and did not
 * finish, or a row an older checkout fabricated. Registering over one of those
 * is how they get in, so blocking it would be blocking the only path they
 * have. `CustomerAuthController` does the claiming; this rule only decides
 * whether the address is genuinely somebody else's already.
 *
 * `unique:users,email` is gone. It was never a tenancy rule — `users` and
 * `customers` are different tables behind different guards — it was a
 * deliberate refusal to let one address be both a business account and a
 * shopper, and it prevented a business owner from ever buying from another
 * business.
 *
 * The unique check can still lose a race to a concurrent signup; the
 * controller maps that duplicate-key QueryException to the same 422 message.
 */
final class CustomerRegisterRequest extends FormRequest
{
    use ResolvesStorefrontContext;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $store = $this->resolveAuthStore($this);

        return [
            'first_name' => ['required', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('customers', 'email')->where(
                    fn ($query) => $query
                        ->where('business_id', $store?->business_id)
                        ->whereNotNull('email_verified_at')
                ),
            ],
            'phone' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Where the sign-up form was shown, and therefore where the terms
            // were accepted: the checkout's onboarding step sends `checkout`,
            // the standalone sign-up screen sends nothing. `source` labels the
            // consent record; it decides nothing, so it is validated to two
            // values rather than trusted as free text.
            'source' => ['sometimes', 'string', Rule::in(['checkout', 'account'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email already has an account here. Please sign in or reset your password.',
        ];
    }
}
