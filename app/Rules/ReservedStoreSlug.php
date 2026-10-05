<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ReservedStoreSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = strtolower(trim((string) $value));

        if ($slug === '') {
            $fail('The :attribute field is required.');

            return;
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
            $fail('The :attribute may only contain lowercase letters, numbers and hyphens.');

            return;
        }

        if (in_array($slug, config('storefront.reserved_subdomains', []), true)) {
            $fail('The :attribute is reserved for the platform and cannot be used.');
        }
    }
}
