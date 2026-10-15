<?php

namespace App\Enums;

/**
 * How a business is registered with the Corporate Affairs Commission.
 *
 * Five, because that is the registry's set rather than a guess at it. A
 * business that is not registered at all does not pick one of these — that is
 * `businesses.is_registered = false`, and the flow skips the whole step.
 *
 * ## Why the number's label lives here
 *
 * Each type calls its registration number something different, and the
 * difference is not cosmetic: a reviewer checking "RC 1234567" against a
 * Business Name's certificate is looking for a number that does not exist. The
 * form label comes from `numberLabel()` so the field says BN where a BN is
 * meant, and there is one place to change when the registry renames something.
 *
 * The values are the stored form. Renaming a case's *value* would orphan every
 * business that already answered, so the value is the stable half and the label
 * is the readable one.
 */
enum BusinessRegistrationType: string
{
    /** A sole proprietorship: one person trading under a registered name. */
    case BUSINESS_NAME = 'business_name';

    /** A private company limited by shares. */
    case LIMITED_COMPANY = 'limited_company';

    /** A public company. Listed, or on its way to being. */
    case PUBLIC_LIMITED_COMPANY = 'public_limited_company';

    case PARTNERSHIP = 'partnership';

    /** A trust, church or association registered as a body corporate. */
    case INCORPORATED_TRUSTEES = 'incorporated_trustees';

    public function label(): string
    {
        return match ($this) {
            self::BUSINESS_NAME => 'Business Name',
            self::LIMITED_COMPANY => 'Limited Liability Company',
            self::PUBLIC_LIMITED_COMPANY => 'Public Limited Company',
            self::PARTNERSHIP => 'Partnership',
            self::INCORPORATED_TRUSTEES => 'Incorporated Trustees',
        };
    }

    /**
     * What this type calls its registration number.
     *
     * Used as the field label, so the form asks for the thing the certificate
     * actually carries.
     */
    public function numberLabel(): string
    {
        return match ($this) {
            self::BUSINESS_NAME => 'BN number',
            self::LIMITED_COMPANY, self::PUBLIC_LIMITED_COMPANY => 'RC number',
            self::PARTNERSHIP => 'Partnership number',
            self::INCORPORATED_TRUSTEES => 'IT number',
        };
    }

    /**
     * The options a form renders, in the order the registry lists them.
     *
     * @return array<int, array{value: string, label: string, number_label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'number_label' => $type->numberLabel(),
            ],
            self::cases(),
        );
    }
}
