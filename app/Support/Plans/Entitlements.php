<?php

namespace App\Support\Plans;

use App\Models\Business;
use App\Models\SubscriptionPlan;

/**
 * What a business is entitled to right now.
 *
 * The single reader of `subscription_plans.entitlements`. Everything that asks
 * "may this business do this" asks here, so that a rule cannot be half-applied
 * — the failure mode the codebase already fixed once for gateway connections,
 * where two screens wrote the same rows and only one enforced the rule.
 *
 * ## Where the answer comes from
 *
 * Three sources, tried in order, and the order is the point:
 *
 * 1. **An active subscription.** The plan the business pays for. This wins over
 *    a trial because a business that has converted should not be governed by
 *    the trial it started.
 * 2. **A live trial.** A trial is a trial *of a plan* — the entitlements the
 *    owner picked when they started it, the top tier included. Read off
 *    `users.selected_plan_id`, which is where the plans page records the choice
 *    while there is no subscription row to record it on.
 * 3. **Nothing.** No plan, no entitlements.
 *
 * ## Why a trial with no plan picked falls back to the default plan
 *
 * A trial starts before the owner has necessarily chosen anything, so
 * `selected_plan_id` can be null with `trial_ends_at` still running — and that
 * is not a corner case, it is the ordinary state of a business in its first
 * week. Answering "no entitlements" there would take away the stores and the
 * screens a trial is *for*, which is the opposite of what a trial means. So an
 * unselected trial resolves to the platform's default plan: try the house
 * offering until you pick one.
 *
 * **And when there is no default plan either** — a deployment before anybody has
 * marked one, which is every fresh install and every test database — a live trial
 * falls back to the registry's own defaults rather than to nothing. Those
 * defaults are the platform's stated position ({@see EntitlementRegistry}): the
 * entry tier's three stores, no gateway of one's own. A live trial is entitled
 * to the house offering, and the house offering has to be answerable without a
 * row being configured first. Unresolved rows are not "unlimited" — nobody gets
 * more from this fallback than the cheapest tier gives.
 *
 * ## And why nothing resolves to nothing
 *
 * With no plan and *no live trial* every toggle is off and every limit is `0` —
 * not unlimited. `EnsureManagementSubscription` already keeps such a business
 * off `/management/*`, so this is the belt to that pair of braces: the failure
 * mode of a limit read as unlimited is a lapsed business running without a cap,
 * and the failure mode of it read as zero is a message the owner can act on.
 * The distinction is the trial, and it is the whole of the difference: a
 * lapsed business is refused, a trialling one is served.
 */
final class Entitlements
{
    public const SOURCE_SUBSCRIPTION = 'subscription';

    public const SOURCE_TRIAL = 'trial';

    public const SOURCE_NONE = 'none';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $effective = null;

    private function __construct(
        private readonly ?SubscriptionPlan $plan,
        private readonly string $source,
    ) {}

    public static function for(?Business $business): self
    {
        if ($business === null) {
            return self::none();
        }

        $subscription = $business->activeSubscription;

        if ($subscription !== null && $subscription->subscriptionPlan !== null) {
            return new self($subscription->subscriptionPlan, self::SOURCE_SUBSCRIPTION);
        }

        $owner = $business->owner;

        if ($owner === null || $owner->trial_ends_at === null || ! $owner->trial_ends_at->isFuture()) {
            return self::none();
        }

        // A live trial is the whole platform, and it is deliberately not
        // resolved through a plan any more.
        //
        // It used to be a trial *of a plan* — the entitlements of whatever the
        // owner had picked, which meant the entry tier unless they had thought
        // to choose the top one. That made the trial a demonstration of the
        // cheapest slice of the product at the exact moment somebody is
        // deciding whether the product is worth paying for, and it made
        // "what do I get?" depend on a choice they had not made yet.
        //
        // It is also the honest reading of what a trial is. Every plan stays
        // selectable throughout — nothing here decides what they may buy — and
        // the plan only starts to govern anything once it is paid for.
        //
        // The whole platform, with exactly one key held back, and the holding
        // back is the catalogue's to declare rather than this method's:
        // `own_gateway` is not lifted by a trial because it moves the business's
        // payments onto its own processor rather than showing it a screen. See
        // {@see EntitlementRegistry::trialGrants()}.
        return new self(null, self::SOURCE_TRIAL);
    }

    /**
     * The same resolution, for the callers that hold a business id and nothing
     * else — the gateway guard is reached from a service that is handed one.
     */
    public static function forBusiness(int $businessId): self
    {
        return self::for(Business::query()->find($businessId));
    }

    public static function forPlan(SubscriptionPlan $plan): self
    {
        return new self($plan, self::SOURCE_SUBSCRIPTION);
    }

    public static function none(): self
    {
        return new self(null, self::SOURCE_NONE);
    }

    public function plan(): ?SubscriptionPlan
    {
        return $this->plan;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * Whether this business holds a plan or a live trial at all.
     *
     * Not `$this->plan !== null`, though the two agree in every case but one: a
     * live trial that resolved to no plan row at all — no `selected_plan_id`, no
     * default plan configured — still holds a trial, and is still entitled to
     * the registry's defaults. Reading it as unresolved would make the one state
     * the fallback exists for the one state it did not cover.
     */
    public function isResolved(): bool
    {
        return $this->source !== self::SOURCE_NONE;
    }

    /**
     * Whether a capability is switched on. Always false when unresolved.
     */
    public function allows(string $toggle): bool
    {
        return ($this->effective()[$toggle] ?? false) === true;
    }

    /**
     * The cap on a metered entitlement, or `null` for unlimited.
     *
     * Zero when unresolved, which is a cap rather than an absence: nothing may
     * be created by a business the platform has granted nothing.
     *
     * `array_key_exists` rather than `??`, and not for tidiness — a null
     * coalesce treats a *stored* null as a missing key, which would read the top
     * tier's unlimited stores as the entry tier's three. It fails loudly for
     * nobody and costs money.
     */
    public function limit(string $key): ?int
    {
        $effective = $this->effective();

        if (! array_key_exists($key, $effective)) {
            return 0;
        }

        $value = $effective[$key];

        return $value === null ? null : (int) $value;
    }

    /**
     * How many more of a thing may be created, or `null` when there is no cap.
     */
    public function remaining(string $key, int $used): ?int
    {
        $limit = $this->limit($key);

        return $limit === null ? null : max(0, $limit - $used);
    }

    /**
     * Whether the cap is reached — including for a business already over it.
     *
     * `<= 0` rather than `=== 0` is what makes a grandfathered business behave:
     * one that dropped to a smaller plan while holding more stores than it
     * allows keeps every store it has, and cannot add another until it is back
     * under the cap. Loosening the platform's rules must never take something
     * away from somebody, and tightening them must not be a way to shed
     * customers either.
     */
    public function reached(string $key, int $used): bool
    {
        $remaining = $this->remaining($key, $used);

        return $remaining !== null && $remaining <= 0;
    }

    /**
     * What the client needs to render the plan it is on, and to sell the one it
     * is not.
     *
     * `entitlements` is what the business holds; `locked` is what it does not,
     * each entry naming the plan that would grant it. Both are here rather than
     * behind a second endpoint because the SPA caches this payload once per
     * session and needs both at the same moment — a sidebar that renders a
     * feature as locked and cannot say what unlocks it is a dead end with an
     * icon on it.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'plan' => $this->plan === null ? null : [
                'id' => $this->plan->id,
                'plan_code' => $this->plan->plan_code,
                'name' => $this->plan->name,
                'tier' => (int) $this->plan->tier,
            ],
            'source' => $this->source,
            'entitlements' => $this->effective(),
            'locked' => $this->locked(),
        ];
    }

    /**
     * Every toggle this business does not hold, and the plan that would grant it.
     *
     * Each entry carries the catalogue's own `label` and `help` as well as the
     * plan, because the upgrade screen has to answer three questions — what is
     * this, which plan has it, what does that cost — and every one of the
     * answers already lives in a table. Sending them makes the SPA's upgrade
     * page a rendering of the catalogue rather than a second copy of it.
     *
     * The price travels with the name because the two are read from the same row
     * at the same moment: an upgrade prompt that says "part of the Enterprise
     * plan" and then quotes a price from somewhere else is a prompt that can
     * disagree with the plan page it sends the owner to.
     *
     * When **no** active plan grants the toggle the entry is still present, with
     * `plan_name` null — so a client can tell "there is no upgrade path for this"
     * from "the payload did not mention it", and say the former instead of
     * rendering a button that leads nowhere. The key is present either way,
     * which is what makes that distinction readable.
     *
     * **Cost:** one active-plan read per locked toggle — three at most, on a table
     * with as many rows as the platform has plans, and only for a payload the SPA
     * fetches once per session. Accepted rather than optimised into a single query
     * by collecting the plans here, because that would put "the cheapest active
     * plan that grants this" in two places, and this codebase has already paid
     * for that mistake once.
     *
     * @return array<string, array{
     *     label: string,
     *     help: string,
     *     plan_name: string|null,
     *     amount: float|null,
     *     currency: string|null,
     * }>
     */
    private function locked(): array
    {
        $locked = [];

        foreach (EntitlementRegistry::all() as $key => $definition) {
            if ($definition['type'] !== EntitlementRegistry::TYPE_TOGGLE || $this->allows($key)) {
                continue;
            }

            $plan = self::planGranting($key);

            $locked[$key] = [
                'label' => (string) $definition['label'],
                'help' => (string) ($definition['help'] ?? ''),
                'plan_name' => $plan?->name,
                // `decimal:2` casts to a string, so this is normalised here
                // rather than left for the client to parse.
                'amount' => $plan === null ? null : (float) $plan->amount,
                'currency' => $plan?->currency,
            ];
        }

        return $locked;
    }

    /**
     * The cheapest active plan that grants a toggle, or null when none does.
     *
     * For refusals that name the plan a business would have to buy. Read from
     * the table rather than written into the message, because every plan's name
     * and price is the admin's to change and a hard-coded "Enterprise" is wrong
     * the first time somebody renames it — which is exactly the sort of
     * staleness this whole feature exists to stop.
     */
    public static function planGranting(string $toggle): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->active()
            ->orderBy('tier')
            ->orderBy('amount')
            ->get()
            ->first(fn (SubscriptionPlan $plan): bool => self::forPlan($plan)->allows($toggle));
    }

    /**
     * The cheapest active plan that would leave room for one more, or null when
     * none does.
     *
     * For refusals that have to name a way forward. "You have reached your
     * limit" with nothing after it is an instruction to leave; the owner needs
     * to know which plan lets them keep going, and — because every plan's name,
     * price and contents are the admin's to change — that has to be read from
     * the table rather than written into the sentence.
     *
     * The test is `> $used`, not "higher than the current plan's limit". A plan
     * can be a step up in price and still not fit the store that was just
     * refused, and naming it would send the owner to a checkout that ends in the
     * same refusal.
     */
    public static function planRaising(string $key, int $used): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->active()
            ->orderBy('tier')
            ->orderBy('amount')
            ->get()
            ->first(function (SubscriptionPlan $plan) use ($key, $used): bool {
                $limit = self::forPlan($plan)->limit($key);

                return $limit === null || $limit > $used;
            });
    }

    /**
     * The grant map in force: every registry key, always, with the registry's
     * default standing in for anything this plan does not state.
     *
     * Complete rather than sparse, because every caller asks one key and has to
     * get an answer — including for a plan whose row predates the column. That
     * is what makes the migration additive.
     *
     * @return array<string, mixed>
     */
    private function effective(): array
    {
        if ($this->effective !== null) {
            return $this->effective;
        }

        // Nothing at all: no plan, no trial. The only case that fails closed.
        //
        // A live trial that found no plan row falls *through* to the registry
        // defaults below with nothing stored, which is deliberate — see the
        // class docblock. `SOURCE_NONE` is the distinction that matters, not
        // "the plan is null".
        // A live trial: every toggle on, every cap lifted — less the keys that
        // opt out, of which there is currently one.
        //
        // Nothing is stored for it, because nothing about it is a plan — the
        // owner may not have chosen one at all, and choosing is what the trial
        // exists to let them do. `null` for a limit is the registry's own
        // spelling of unlimited; `array_key_exists` in `limit()` is what keeps
        // that distinct from a cap of zero.
        //
        // An opted-out key answers the catalogue's own `default` rather than
        // `false`, so that opting out of a trial means "the trial does not lift
        // this" rather than "this is off unless a plan says otherwise" — for the
        // key that does it today the two are the same answer, and the difference
        // is the one that keeps this branch from overruling the catalogue.
        // {@see EntitlementRegistry::trialGrants()}, and the class docblock there
        // for why `own_gateway` is the exception.
        if ($this->source === self::SOURCE_TRIAL) {
            $effective = [];

            foreach (EntitlementRegistry::all() as $key => $definition) {
                $effective[$key] = match (true) {
                    $definition['type'] === EntitlementRegistry::TYPE_LIMIT => null,
                    ! EntitlementRegistry::trialGrants($key) => $definition['default'],
                    default => true,
                };
            }

            return $this->effective = $effective;
        }

        if ($this->plan === null && $this->source === self::SOURCE_NONE) {
            return $this->effective = array_map(
                static fn (array $definition): mixed => $definition['type'] === EntitlementRegistry::TYPE_LIMIT ? 0 : false,
                EntitlementRegistry::all(),
            );
        }

        $stored = $this->plan !== null && is_array($this->plan->entitlements)
            ? $this->plan->entitlements
            : [];

        $effective = [];

        foreach (EntitlementRegistry::all() as $key => $definition) {
            // `array_key_exists`, not `??`. A limit stored as null means
            // unlimited, and a null coalesce would quietly swap the top tier's
            // defining feature for the registry's entry-tier default — the one
            // mistake in this class that would cost somebody money.
            $effective[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : $definition['default'];
        }

        return $this->effective = $effective;
    }
}
