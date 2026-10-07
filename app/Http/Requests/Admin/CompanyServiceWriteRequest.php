<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-18 (admin console) — the company-service create/edit payload.
 *
 * Create and edit share one contract on purpose: the previous controller
 * validated both actions with the same rule set. The only difference is the
 * page-link uniqueness check, which ignores the edited row's own value
 * (`forUpdate()`); everything else — the normalisation, the scheme/traversal
 * guards and the image rules — is identical.
 *
 * Provenance kept from the controller: **the page link is validated after
 * normalisation.** Legacy validated uniqueness on the raw input and stripped
 * the leading slash afterwards, so `/about` and `about` passed validation as
 * different values and then collided on the unique index (a 500). Laravel
 * runs `prepareForValidation()` before the rules, so uniqueness, the
 * scheme/`..` guards and the stored value all see the same normalised string.
 */
abstract class CompanyServiceWriteRequest extends FormRequest
{
    /**
     * The only statuses a row can hold — the filter list reuses this set so a
     * filter can never name a value the write side would not accept.
     */
    public const STATUSES = ['active', 'inactive'];

    /**
     * Update requests ignore the bound row in the page-link unique check.
     */
    abstract protected function forUpdate(): bool;

    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller so its
        // order relative to route binding is unchanged.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Non-string input is left untouched so the `string` rule reports it
        // rather than this merge turning a 422 into a TypeError.
        if (is_string($this->input('page_link'))) {
            $this->merge(['page_link' => $this->normalizePageLink($this->input('page_link'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'order' => ['nullable', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'page_link' => [
                'nullable',
                'string',
                'max:255',
                // A stored link is a site-relative path, never a scheme or a
                // traversal: the office renders it as `https://host/<link>`.
                function (string $attribute, mixed $value, Closure $fail) {
                    // The `string` rule rejects non-strings; guard anyway so an
                    // array input cannot reach preg_match/str_contains below
                    // and turn a 422 into a 500.
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1) {
                        $fail('The page link must be a path on this site, not a full URL or scheme.');
                    }

                    if (str_contains($value, '..')) {
                        $fail('The page link cannot contain "..".');
                    }

                    if (preg_match('/\s/', $value) === 1) {
                        $fail('The page link cannot contain spaces.');
                    }
                },
                $this->forUpdate()
                    ? Rule::unique('company_services', 'page_link')->ignore($this->route('companyService')?->id)
                    : Rule::unique('company_services', 'page_link'),
            ],
            'background_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }

    /**
     * `https://host//about/` -> `about`; empty input -> null. Legacy's
     * normalisation, kept, now applied before validation.
     */
    private function normalizePageLink(string $value): ?string
    {
        $link = trim($value);
        $link = ltrim($link, '/');

        return $link === '' ? null : $link;
    }
}
