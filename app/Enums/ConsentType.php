<?php

namespace App\Enums;

/**
 * The things a customer can be asked to agree to.
 *
 * Kept as an enum rather than free strings in the `customer_consents` column so
 * the storefront's toggles, the registration record and the management screen
 * cannot drift apart on spelling — but the column stays a varchar, so adding a
 * case here is a code change and not a migration.
 *
 * `TERMS` is recorded, never toggled: accepting the terms is a condition of
 * having an account, so its row is written once at registration (or when a
 * checkout-fabricated row is claimed) and the storefront does not offer a
 * switch for it. `MARKETING` is the one the customer controls.
 */
enum ConsentType: string
{
    case TERMS = 'terms';
    case MARKETING = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::TERMS => 'Terms and privacy policy',
            self::MARKETING => 'Product news and offers',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TERMS => 'You agreed to the store\'s terms of service and privacy policy.',
            self::MARKETING => 'Occasional emails about new products, discounts and store news.',
        };
    }

    /**
     * Whether the customer may change their own answer.
     */
    public function isOptional(): bool
    {
        return $this === self::MARKETING;
    }

    /**
     * What a customer who has never been asked is taken to have said.
     *
     * Marketing is off until it is asked for — the record has to show an active
     * choice, not an omission. Terms is on by the fact of registering.
     */
    public function default(): bool
    {
        return match ($this) {
            self::TERMS => true,
            self::MARKETING => false,
        };
    }

    /**
     * The consent kinds a storefront request may set.
     *
     * @return array<int, string>
     */
    public static function settableValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->isOptional()),
        ));
    }
}
