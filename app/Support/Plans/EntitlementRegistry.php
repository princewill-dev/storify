<?php

namespace App\Support\Plans;

use App\Support\Payments\GatewayGrant;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Plugins\PluginRegistry;

/**
 * The catalogue of what a plan may grant, and what it may limit.
 *
 * Structurally identical to {@see PaymentGatewayRegistry}
 * and {@see PluginRegistry} on purpose — same static
 * definitions, same `cataloguePayload()`, same registry-driven validation — so
 * the admin plan editor is built from data and a new entitlement is one entry
 * here plus one check at its enforcement point, with no migration and no
 * frontend change.
 *
 * ## Why this exists at all
 *
 * `subscription_plans.features` is a list of display strings. It is rendered —
 * on the marketing site, in the admin console, on the business's own plans page
 * — and it is read by no code anywhere. So a plan has never actually *contained*
 * anything: every business, on every plan, has been able to create the same
 * number of stores and reach the same providers. This registry is what turns a
 * plan from a price and a bullet list into a set of enforceable rights, stored
 * as `subscription_plans.entitlements`.
 *
 * `features` is deliberately left alone. It is the marketing copy, it is
 * serialized by four resources and by the marketing site, and reshaping it would
 * break all of them to say something this column already says better.
 *
 * ## Two types, and why a limit is not a toggle
 *
 * A `limit` is a number or `null`, and `null` means **unlimited** — which is a
 * grant in its own right, and is most of what the top tier sells. A `toggle` is
 * on or off. They are separate types because they are answered by separate
 * methods ({@see Entitlements::limit()} and {@see Entitlements::allows()}) and
 * because the usual way to collapse them — `if ($value)` — silently turns the
 * unlimited plan into a plan with nothing.
 *
 * ## Declared ahead of being enforced
 *
 * `staff_limit`, `products_limit` and `warehouses_limit` are declared here and
 * enforced nowhere yet. Declaring them is what makes the admin editor complete
 * and the next limit a one-line call rather than a migration, an admin form and
 * a release. They are listed rather than omitted so the set of things a plan can
 * say is visible in one place — but nothing reads them today, and this file
 * describes the platform's vocabulary, not its gates.
 */
final class EntitlementRegistry
{
    public const TYPE_LIMIT = 'limit';

    public const TYPE_TOGGLE = 'toggle';

    public const STORES = 'stores_limit';

    public const STAFF = 'staff_limit';

    public const PRODUCTS = 'products_limit';

    public const WAREHOUSES = 'warehouses_limit';

    /**
     * The one entitlement the payment settings screen turns on.
     *
     * Named for what it grants rather than for the column it replaces. The
     * per-business grant ({@see GatewayGrant}) is a
     * different thing that answers the same question — see that class for why
     * the two are OR'd rather than one replacing the other.
     */
    public const OWN_GATEWAY = 'own_gateway';

    public const STOREFRONT = 'storefront';

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        return [
            self::STORES => [
                'label' => 'Stores',
                'help' => 'How many stores this plan may run at once. Leave blank for no limit.',
                'type' => self::TYPE_LIMIT,
                'default' => 3,
                'unit' => 'stores',
            ],

            self::STAFF => [
                'label' => 'Staff accounts',
                'help' => 'How many staff accounts this plan may create. Leave blank for no limit.',
                'type' => self::TYPE_LIMIT,
                // Null — unlimited — rather than a number, because nothing
                // reads this yet and a default that reads as a restriction
                // would be enforced by surprise the day it is wired up.
                'default' => null,
                'unit' => 'staff',
            ],

            self::PRODUCTS => [
                'label' => 'Products',
                'help' => 'How many products this plan may hold. Leave blank for no limit.',
                'type' => self::TYPE_LIMIT,
                'default' => null,
                'unit' => 'products',
            ],

            self::WAREHOUSES => [
                'label' => 'Warehouses',
                'help' => 'How many warehouses this plan may hold. Leave blank for no limit.',
                'type' => self::TYPE_LIMIT,
                'default' => null,
                'unit' => 'warehouses',
            ],

            self::OWN_GATEWAY => [
                'label' => 'Connect your own payment gateway',
                'help' => 'Reach the payment provider catalogue and connect the business\'s own keys.',
                'type' => self::TYPE_TOGGLE,
                // Off, and that is the platform's whole position: for a small
                // shop a gateway of its own means a merchant account, its own
                // KYC and its own settlement schedule — the friction a platform
                // exists to remove. Only the top tier and individually granted
                // businesses have it.
                'default' => false,
                'unit' => null,
            ],

            self::STOREFRONT => [
                'label' => 'Online storefronts',
                'help' => 'Publish a public storefront for each store.',
                'type' => self::TYPE_TOGGLE,
                'default' => true,
                'unit' => null,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        static $catalogue = null;

        return $catalogue ??= self::definitions();
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function type(string $key): ?string
    {
        return self::all()[$key]['type'] ?? null;
    }

    /**
     * What a plan grants for this key when it says nothing about it.
     *
     * The answer for a plan whose row predates the column, and the reason the
     * migration adding `entitlements` is additive rather than a backfill that
     * has to guess. An unknown key answers `null`, which every caller reads as
     * "not granted".
     */
    public static function defaultFor(string $key): mixed
    {
        return self::all()[$key]['default'] ?? null;
    }

    /**
     * The registry as the admin editor needs it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cataloguePayload(): array
    {
        $payload = [];

        foreach (self::all() as $key => $definition) {
            $payload[] = [
                'key' => $key,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'type' => $definition['type'],
                'default' => $definition['default'],
                'unit' => $definition['unit'],
            ];
        }

        return $payload;
    }

    /**
     * Turn whatever the admin form posted into the map that gets stored.
     *
     * Three jobs, and the first is the one that makes this worth having at a
     * boundary rather than trusting the request: **unknown keys are dropped**,
     * so a key left behind by an entitlement that no longer exists cannot sit in
     * a plan's JSON being read by nothing and confusing everyone who looks at
     * the column. The other two are coercion and the blank-means-unlimited rule.
     *
     * A blank limit is `null`, not `0`, and the difference is the whole of the
     * top tier: an empty number box means "no ceiling", and reading it as zero
     * would turn the enterprise plan into the one that cannot create a store.
     * A limit that is present, numeric and non-negative is stored as an integer;
     * anything else is dropped so the registry default applies rather than the
     * admin's typo.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $clean = [];

        foreach (self::all() as $key => $definition) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if ($definition['type'] === self::TYPE_LIMIT) {
                if ($value === null || $value === '') {
                    $clean[$key] = null;

                    continue;
                }

                if (! is_numeric($value) || (int) $value < 0) {
                    continue;
                }

                $clean[$key] = (int) $value;

                continue;
            }

            $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return $clean;
    }
}
