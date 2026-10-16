<?php

namespace App\Support\Plans;

use App\Services\Accounting\LedgerPostingService;
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
 * ## Declaring here is a promise to enforce
 *
 * Every key below has a real enforcement point, and a test
 * (`ws55planfeaturegateTest.php`) fails on any key that does not — because the
 * failure mode this catalogue is most prone to is a key that can be set in the
 * admin console, is shown to admins as a real setting, and is read by no code.
 * That is not hypothetical: it is exactly what happened to the store limit,
 * which the platform stored, displayed and validated while reading it from no
 * creation path, so a three-store plan could run thirty.
 *
 * The enforcement points, one per key:
 *
 * | key | enforced at |
 * |---|---|
 * | `stores_limit` | {@see StoreQuota} / {@see PlanQuota}, on store creation |
 * | `staff_limit` | {@see PlanQuota}, on staff creation |
 * | `products_limit` | {@see PlanQuota}, on product creation |
 * | `warehouses_limit` | {@see PlanQuota}, on warehouse creation |
 * | `own_gateway` | {@see GatewayGrant}, on connecting own payment keys |
 * | `storefront` | {@see FeatureGate}, on publishing a storefront |
 * | `accounting` | {@see FeatureGate}, on every accounting route |
 * | `pos` | {@see FeatureGate}, on every `/api/v1/pos/*` route and POS oversight |
 * | `plugins` | {@see FeatureGate}, on the plugin routes |
 * | `invoices` | {@see FeatureGate}, on the invoice routes |
 *
 * ## Why these three default to `false`, and what that cost
 *
 * `pos`, `plugins` and `invoices` were added after `accounting` and default to
 * `false` like it, which is the safe direction for a *restriction* and the
 * dangerous one for a *grant*: a plan row that says nothing about a toggle
 * receives the default, so every plan written before these keys existed would
 * have lost all three at deploy.
 *
 * That is why
 * 2026_10_14_000003_fill_module_entitlements_from_tier_names.php exists and why
 * the three seeded tiers state all three keys explicitly — every row this
 * platform ships says yes or no out loud. Any key added after this one has the
 * same obligation.
 *
 * ## The one key a trial does not lift
 *
 * A live trial resolves to every toggle on and every cap lifted, and that is
 * deliberate: a trial that demonstrates the entry tier makes "what do I get?"
 * depend on a plan the owner has not chosen yet ({@see Entitlements}).
 *
 * `own_gateway` is the exception, and it is the only one. It carries
 * `'trial' => false`, which makes a trial answer the catalogue's own `default`
 * for it — off — instead of on. The reason is that this key is not a screen to
 * look at while deciding; it is a change in **who processes the business's
 * money**. A business that connects its own keys takes payments through its own
 * merchant account, and a connection made during a trial is not unmade by the
 * trial ending ({@see GatewayGrant} — revoking the grant stops the keys being
 * *changed*, not the business being charged through them). A free window in
 * which any signup can route payments out of the platform's processing, and keep
 * them there afterwards, is the loophole the whole of {@see GatewayGrant} exists
 * to close — so it is granted by the top tier and by an admin, and not by time.
 *
 * The flag is deliberately **not** in {@see cataloguePayload()}: it is not a
 * plan setting an admin edits, it is the platform's own position on one key, and
 * putting it in the plan editor would read as a per-plan choice worth making.
 *
 * Any key added later that is a *change in the commercial relationship* rather
 * than a feature of the software belongs here beside this one.
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
     * The books: the ledger, expenses, suppliers, bills, reconciliation and the
     * reports built from them.
     *
     * Off by default, and off is a real position rather than a placeholder. Most
     * of what a small shop needs is an order list and a payout total, both of
     * which the platform shows without any of this. Double-entry accounting is
     * for a business that has somebody to read it.
     *
     * Note what this does *not* gate: the ledger is still written for every
     * business, on every order, sale and payment ({@see LedgerPostingService}),
     * and every business is still seeded a chart of accounts at setup. Gating
     * the write path would mean unwinding entries on a downgrade and rebuilding
     * them on an upgrade; gating the screens means an upgrade is instant and a
     * downgrade costs nothing. What is Enterprise-only is being able to *look*.
     */
    public const ACCOUNTING = 'accounting';

    /**
     * The till: the POS application, and the console screens that oversee it.
     *
     * Off by default, and this is the most consequential default in the
     * catalogue — a business on a plan that says nothing about it loses point of
     * sale entirely, staff sign-in included. See the class docblock for why every
     * shipped plan row states this key explicitly rather than inheriting it.
     *
     * What it gates is `routes/api/v1/pos.php` from the point a user is resolved
     * onwards. `POST /pos/login` is deliberately outside it: there is no
     * authenticated user at that point, so there is nothing to ask the plan
     * about, and refusing a sign-in would be a refusal in a costume nobody can
     * read. Everything after it — the session, the catalogue, the checkout — is
     * behind the gate, which is what makes this "the plan does not include the
     * till" rather than "the till is read-only".
     */
    public const POS = 'pos';

    /**
     * Third-party marketing and analytics integrations, configured per business
     * or per store.
     *
     * Gates the settings screen that turns an integration on. What a *plugin*
     * does once installed — a tracking pixel on a storefront, a review widget —
     * is not gated by this and is not meant to be: the toggle governs whether the
     * business may configure integrations at all, the same line
     * {@see PluginRegistry} already draws between available and enabled.
     */
    public const PLUGINS = 'plugins';

    /**
     * Invoices: raising one against an order, sending it, and recording payment.
     *
     * Gates the management module and the till's invoice endpoints together,
     * because they are one feature reached two ways. The whole surface is those
     * two — there is no public route a customer follows to an invoice, so this
     * refuses the PDF with the rest of the module rather than leaving a document
     * reachable by a business whose plan does not include invoices at all.
     *
     * Note the ledger parallel and where it stops: invoices already issued and
     * posted stay posted, because nothing here touches the write path. What a
     * business loses on a downgrade is the screen, not the record.
     */
    public const INVOICES = 'invoices';

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
                // Null — unlimited — and that is now the platform's stated
                // position for every seeded tier rather than a placeholder. The
                // seeded `features` copy has said "Unlimited products" from the
                // start, so a number here would contradict the plan card.
                'default' => null,
                // Plural, because {@see PlanQuota} writes its refusals in the
                // plural and English will not take an "s" bolted on. "staff" was
                // also wrong on its own terms: what is limited is accounts, and
                // an owner reads "no more staff" as being about people.
                'unit' => 'staff accounts',
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
                // And a trial is not one of the three ways to get it. The only
                // key in the catalogue that says this — see the class docblock
                // for why this one is different from every other toggle.
                'trial' => false,
                'unit' => null,
            ],

            self::STOREFRONT => [
                'label' => 'Online storefronts',
                'help' => 'Publish a public storefront for each store.',
                'type' => self::TYPE_TOGGLE,
                'default' => true,
                'unit' => null,
            ],

            self::ACCOUNTING => [
                'label' => 'Accounting',
                'help' => 'The books — ledger accounts, journal, expenses, suppliers, bills, bank reconciliation and financial reports.',
                'type' => self::TYPE_TOGGLE,
                // Off by default, so every plan that says nothing about it gets
                // no accounting — including a plan that predates this key. That
                // is the safe direction for a *restriction* and the unsafe
                // direction for a *grant*, which is why the plan rows that
                // should grant it have to say so: see
                // 2026_10_14_000002_fill_plan_entitlements_from_tier_names.php.
                'default' => false,
                'unit' => null,
            ],

            self::POS => [
                'label' => 'Point of sale',
                'help' => 'The till application — register sessions, offline-capable checkout, and the console screens that oversee them.',
                'type' => self::TYPE_TOGGLE,
                // Off, like accounting and for the same reason: this is the
                // top tier's differentiator rather than a platform-wide right.
                // The cost of getting it wrong is higher here — see the POS
                // constant for what a business on a plan that says nothing
                // loses.
                'default' => false,
                'unit' => null,
            ],

            self::PLUGINS => [
                'label' => 'Plugins',
                'help' => 'Connect third-party marketing and analytics integrations, for the business or for a single store.',
                'type' => self::TYPE_TOGGLE,
                'default' => false,
                'unit' => null,
            ],

            self::INVOICES => [
                'label' => 'Invoices',
                'help' => 'Raise an invoice against an order, send it, and record what comes back.',
                'type' => self::TYPE_TOGGLE,
                'default' => false,
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
     * Whether a live trial turns this key on.
     *
     * True for every key that does not say otherwise, because a trial is the
     * whole platform — which is what makes the exception a decision rather than
     * a default. The one key that opts out ({@see self::OWN_GATEWAY}, marked
     * `'trial' => false`) is a change in who processes the business's money
     * rather than a screen to look at; see the class docblock.
     *
     * Only the toggle half is asked. A trial lifts every cap, and a cap is not
     * something a trial can opt out of: a limit read as `0` for a trialling
     * business would take away the stores the trial exists to demonstrate.
     */
    public static function trialGrants(string $key): bool
    {
        return (self::all()[$key]['trial'] ?? true) === true;
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
