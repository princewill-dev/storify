<?php

namespace App\Http\Requests\Management;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The business half of onboarding — the screen that asks "How can we be of
 * help", and the call that creates the business row.
 *
 * ## Why this call is the one that creates the business
 *
 * A plan cannot be priced without a business: the checkout resolves the plan
 * from the business's owner and is scoped to it. So the business has to exist
 * before the plan page, and intent has to be stored somewhere at the same
 * moment. `POST management/setup` is already the endpoint for exactly this
 * moment — it is one of the few management routes reachable *before* a
 * business exists — so the interests screen is this call wearing a new face
 * rather than a new endpoint.
 *
 * `name` is now optional. It is derived from the email when it is absent,
 * because the business name is confirmed later, on the payment settings
 * screen, and asking for it here was the form field the redesign set out to
 * remove. The derivation is a placeholder and is treated as one everywhere it
 * is read.
 */
class SetupBusinessRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'business_location' => ['nullable', 'string', 'max:100'],

            // The answers themselves. Validated against the model's list so an
            // answer cannot be accepted here and be meaningless on the other
            // side of the wire.
            'intents' => ['nullable', 'array'],
            'intents.*' => ['string', Rule::in(Business::ONBOARDING_INTENTS)],
        ];
    }
}
