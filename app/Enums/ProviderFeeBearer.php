<?php

namespace App\Enums;

/**
 * Whose money the payment provider's processing fee comes out of.
 *
 * Two values, and the type is worth having anyway, because the failure this
 * guards against is a typo: `'platfrom'` in a writer would not throw, it would
 * quietly mean "the business paid it" — a business underpaid by a few naira on
 * every sale, with nothing to point at.
 *
 * | Case | Means |
 * |---|---|
 * | `BUSINESS` | the provider deducted from the business's own account — a sale charged on the business's own keys, and every row written before this rule existed |
 * | `PLATFORM` | Storify charged on its own credentials, so the provider's fee is Storify's expense and is not part of the business's share |
 */
enum ProviderFeeBearer: string
{
    case BUSINESS = 'business';
    case PLATFORM = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::BUSINESS => 'Business',
            self::PLATFORM => 'Storify',
        };
    }

    /** Whether the business's share of the sale has this fee taken out of it. */
    public function isBusiness(): bool
    {
        return $this === self::BUSINESS;
    }

    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
