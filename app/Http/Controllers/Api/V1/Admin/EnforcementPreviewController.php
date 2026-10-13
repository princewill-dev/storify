<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What turning the system processor on would do to every business — before it is
 * turned on.
 *
 * The switch is the most consequential thing in the admin console. Under
 * enforcement Storify collects every sale itself, and the only businesses it can
 * do that for are the ones it is holding money for. A business that has not been
 * moved over therefore **takes no payments at all** once the switch is on,
 * including cash at the till — the enforced path replaces the whole provider list
 * rather than adding to it. An admin clicking through a confirmation dialog that
 * does not say how many businesses that is has not been told what they are doing.
 *
 * So this answers, ahead of the click: how many businesses would stop being able
 * to sell, which ones, why each of them, and how many stores sit behind them.
 *
 * ## What a business can be blocked by now
 *
 * It used to be an account at the provider — a per-business payee that had to
 * exist before the business could be charged through. That account has been
 * deleted along with the split-at-source rail, and what is left is much shorter:
 *
 * - **Not collected yet** (`businesses.custody_enabled` is false). The business is
 *   still on its own keys, so the platform has nothing to charge with and no
 *   record to hold the money against. This is the list the switch creates.
 * - **Switched off by its owner.** The one per-business row that still means
 *   something: an owner who has turned Storify payments off for a store. A row
 *   that says *on* is the same as no row at all.
 *
 * Neither is about a bank account any more, and that is the point of the change:
 * a business with nowhere to be paid can sell all day, because the money is held
 * for it and waits. A missing destination is therefore **not** on this list — it
 * is the separate one at the bottom.
 *
 * ## `?processor=` — the answer that is worth something
 *
 * With no parameter the counts describe the processor **in force**, which is the
 * only thing a pre-flight can say once the switch is already on — and by then the
 * decision has been made. `?processor=bachs` asks the question that still matters:
 * what would happen if it were this one. The panel asks it for each option before
 * anything is saved, so the numbers are in front of the admin while they are
 * still choosing, and the state fields (`enforced`, `stored`, `processor`) keep
 * reporting the present regardless of what was asked about.
 *
 * Neither of the two blockers depends on which processor was asked about — being
 * collected, and being switched off, are facts about the business rather than
 * about a provider — so in practice the two scenarios differ only in the notes
 * read from the switch rows. The parameter is kept because the panel's whole
 * vocabulary is built on asking the same question twice.
 *
 * ## Why it does not use the resolver
 *
 * {@see PaymentGatewayResolver} is the authority on
 * whether a store can charge, and asking it here would be the surest way of
 * agreeing with it. It is also the wrong tool: it resolves one store at a time,
 * and a platform-wide list would be one query per store per provider.
 *
 * What this does instead is read the same **facts** the resolver reads — the
 * custody flag on the business, and the owner's switch on the effective row —
 * and applies the same precedence: a store's own row beats the business's. It
 * makes **no provider calls**: every fact needed here is already stored, and a
 * pre-flight that took two minutes of API time would be a pre-flight nobody runs.
 *
 * ## Three lists, two of them short
 *
 * Businesses that would break are listed in full — they are the ones somebody
 * has to act on, and there are meant to be few. Businesses that would keep
 * working are counted and not listed: a platform with ten thousand businesses
 * cannot render that list, and there is nothing to do about any of them.
 *
 * The third list is the one enforcement does **not** create but that a pre-flight
 * is the right place to see: businesses that keep taking money and have nowhere
 * for it to go. Listed, unlike the working ones, because somebody has to ask them
 * for a bank account — but *not* counted as locked out. Being unable to be paid
 * does not stop a business selling, and reporting it as if it did would send an
 * admin to fix a business that is trading happily.
 */
class EnforcementPreviewController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // `processor` asks about a processor other than the one in force — the
        // question an admin actually has, because the only moment its answer can
        // still change their mind is *before* the click. An unknown code is a 422
        // rather than a quiet fallback to the current setting: a panel that asked
        // about Bachs and was shown Paystack's numbers would be worse than one
        // told its request was wrong.
        $request->validate([
            'processor' => ['nullable', 'string', Rule::in(SystemProcessor::selectableCodes())],
        ]);

        $processor = SystemProcessor::code();
        $stored = SystemProcessor::stored();

        // Which processor the numbers below describe: the one asked about, or the
        // one in force when nothing was asked. Null means there is no scenario to
        // count — the switch is off and no candidate was named — and with no
        // processor there is nobody to lock out.
        $countedFor = $request->query('processor') ?? $processor;

        // `business_code` rides along so the console can link each affected
        // business to its own screen: a list of names an admin has to search for
        // one at a time is a list they will not act on. `custody_enabled` is the
        // fact the first sentence of {@see self::problemFor()} turns on, and
        // `payout_bank_id` the one the last section of this method turns on.
        $businesses = DB::table('businesses')
            ->get(['id', 'business_code', 'name', 'payout_bank_id', 'custody_enabled']);

        // One query for every store on the platform, rather than one per
        // business: this runs while an admin is waiting for a dialog to open,
        // and the per-business shape would be a query per business per scope.
        $storesByBusiness = $this->storeIdsByBusiness();
        $storesTotal = array_sum(array_map('count', $storesByBusiness));

        $lockedOut = [];
        $lockedOutStores = 0;

        // Businesses that would keep selling but whose collections have nowhere
        // to land. Not a lock-out — see {@see self::payabilityGap()} — and kept
        // apart from the list above so the two are never read as one.
        $unpayable = [];

        // Broken down by what an admin would do about it: businesses to move onto
        // the new rail, and businesses to ask why they switched it off.
        $counts = ['custody' => 0, 'connection' => 0];

        // Whether the platform's own switch for this processor is on — the fourth
        // way a store can end up unchargeable, and the only one that is not about
        // a business at all. Reported as a field rather than multiplied across
        // every business in the list, because when it is off *every* business is
        // locked out and a list of all of them would bury the one fact that
        // matters.
        $platformOffers = $countedFor === null || $this->platformOffers($countedFor);

        // Nothing is counted when there is no processor to count against — the
        // switch is off, and no candidate was named. Skipping the pass keeps that
        // case free of queries as well as of invented answers.
        if ($countedFor !== null) {
            $switches = $this->switchRows($countedFor);

            // The banks the payout run pays into, in one query for the same
            // reason the stores are. Read from the table rather than through the
            // business model's `payoutBank` relation, which would be a query per
            // business for a pre-flight that is meant to be instant.
            $banks = DB::table('store_banks')
                ->whereIn('id', $businesses->pluck('payout_bank_id')->filter()->unique()->all())
                ->get(['id', 'bank_code', 'account_number'])
                ->keyBy('id');

            foreach ($businesses as $business) {
                $businessId = (int) $business->id;
                $storeIds = $storesByBusiness[$businessId] ?? [];

                $custody = (bool) $business->custody_enabled;
                $businessOff = $switches['business'][$businessId] ?? null;

                $problem = $this->problemFor($custody, $businessOff);

                // A store's own row is the *effective* row when it exists, so it
                // is the store's switch that decides — falling back to the
                // business's, exactly as the resolver does. A store breaks when
                // either the business is not collected, or the row it ends up
                // reading says off.
                $broken = 0;

                foreach ($storeIds as $storeId) {
                    $off = $switches['store'][$storeId] ?? $businessOff;

                    if ($this->problemFor($custody, $off)['reason'] !== null) {
                        $broken++;
                    }
                }

                $lockedOutStores += $broken;

                if ($problem['reason'] === null && $broken === 0) {
                    // Working, and worth a line only if the money it takes has
                    // nowhere to go once it has been taken.
                    $gap = $this->payabilityGap($banks->get((int) $business->payout_bank_id));

                    if ($gap !== null) {
                        $unpayable[] = [
                            'business_id' => $businessId,
                            'business_code' => $business->business_code,
                            'name' => (string) $business->name,
                            'stores' => count($storeIds),
                            'reason' => $gap,
                        ];
                    }

                    continue;
                }

                // The business's own state decides the sentence; when the
                // business is fine and only some of its stores are not, the
                // sentence says exactly that instead of blaming the business for
                // a switch one of its stores pulled.
                $kind = $problem['reason'] !== null ? $problem['kind'] : 'connection';

                $counts[$kind] = ($counts[$kind] ?? 0) + 1;

                $lockedOut[] = [
                    'business_id' => $businessId,
                    'business_code' => $business->business_code,
                    'name' => (string) $business->name,
                    'stores' => count($storeIds),
                    'stores_locked_out' => $broken,
                    'kind' => $kind,
                    'reason' => $problem['reason'] ?? 'Some of this business\'s stores cannot take payments.',
                ];
            }
        }

        return $this->ok([
            // Off, or on with a name this build cannot run: both are "everything
            // is meant to go through one processor", which is why this is read
            // from the stored value rather than from the runnable code.
            'enforced' => $stored !== null,
            'stored' => $stored,
            'processor' => $processor,
            'processor_name' => SystemProcessor::name(),
            // Null unless the switch is on *and* the stored name is one this
            // build cannot run — the one state that reads as enforced to every
            // caller and can take no money at all.
            'processor_problem' => SystemProcessor::unavailableReason(),
            // Whether the platform's own switch for the processor being counted
            // is on. False means nothing can be charged through it anywhere, so
            // the lists below describe businesses that are blocked for a reason
            // they cannot act on.
            'platform_offers_processor' => $platformOffers,
            'processors' => $this->processors(),
            // What the counts below answer: the processor asked about, or the
            // one in force. Carried so a panel can label the numbers instead of
            // leaving an admin to guess which scenario they describe.
            'counted_for' => $countedFor,
            'counted_for_name' => $countedFor === null ? null : $this->name($countedFor),
            'businesses_total' => $businesses->count(),
            'businesses_locked_out' => count($lockedOut),
            // The breakdown an admin acts on: who has to be moved onto the new
            // rail, and who has asked not to be charged through it.
            'businesses_awaiting_custody' => $counts['custody'],
            'businesses_switched_off' => $counts['connection'],
            'stores_total' => $storesTotal,
            'stores_locked_out' => $lockedOutStores,
            'businesses' => $lockedOut,
            // Not a lock-out, and deliberately a separate list: these businesses
            // would keep taking payments through the switch. What they cannot do
            // is have the money handed over, so it accumulates in Storify's
            // balance until somebody names a bank.
            'businesses_cannot_be_paid' => count($unpayable),
            'cannot_be_paid' => $unpayable,
        ]);
    }

    /**
     * The processors an admin may choose between, as `code`/`name` pairs.
     *
     * Carried here as well as on `SettingsResource` for a reason that is about
     * permissions rather than about this screen: the switch itself is written
     * through `PUT /admin/settings`, which needs `admin.settings`, while this
     * endpoint needs only `admin.finance` — and the Payments tab is visible to
     * either. Without these the tab could not render its own selector for a
     * finance-only admin, who must still be able to *read* what the platform is
     * set to. Both lists come from {@see SystemProcessor::selectableCodes()},
     * so the registry stays the one place a provider gains or loses a
     * platform-charging path.
     *
     * @return array<int, array{code: string, name: string}>
     */
    private function processors(): array
    {
        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'name' => $this->name($code),
            ],
            SystemProcessor::selectableCodes(),
        );
    }

    /**
     * Why this business could not take payments, and what kind of problem it is.
     *
     * A `reason` of null means it could take payments. The `kind` is which of
     * those reasons it is, as a word the console can count by — the sentence
     * itself is for reading, and string-matching it to group a list would break
     * the first time somebody improves the wording.
     *
     * Two sentences, and the order they are asked in matters. Custody is asked
     * first because it is the one that subsumes the other: a business that has
     * not been moved over cannot be charged through the platform whatever its
     * rows say, so telling its owner to switch payments back on would be advice
     * that changes nothing.
     *
     * ## Where the bank account went
     *
     * Both sentences here used to be about a bank account, because a charge had
     * to name somewhere for the provider to pay the business directly. It does
     * not any more: Storify collects and pays out, so there is nothing to strand
     * and a missing destination is a delay rather than a block. That is the whole
     * shape of the change, and it is why the bank account is now a *separate*
     * question — see {@see self::payabilityGap()}.
     *
     * What is preserved is the ordering discipline: the reasons here are the ones
     * {@see PaymentGatewayResolver} would give, so an admin reading this screen
     * while an owner reads their own is not possible to be told two things.
     *
     * @param  ?bool  $switchedOff  the effective row's `is_active`, or null when
     *                              no row exists at all — which is not a "no"
     * @return array{reason: ?string, kind: string}
     */
    private function problemFor(bool $custody, ?bool $switchedOff): array
    {
        if (! $custody) {
            return [
                'reason' => 'Its sales are not yet collected by Storify, so there is nothing to charge them with.',
                'kind' => 'custody',
            ];
        }

        if ($switchedOff === false) {
            return ['reason' => 'Payments are switched off for this connection.', 'kind' => 'connection'];
        }

        return ['reason' => null, 'kind' => 'ready'];
    }

    /**
     * Why money collected for this business could not be handed over, or null
     * when it could.
     *
     * A separate question from {@see self::problemFor()}, and separate on
     * purpose. A missing destination used to be a reason a business could not
     * trade — the provider paid the business directly, so an account with
     * nowhere to pay out to was a charge that stranded money. It is no longer
     * one: collected money is held for the business and paid out once there is
     * somewhere to pay it, so a business with no destination can take a
     * customer's money all day. Reporting it as a lock-out would tell an admin
     * to go and fix a business that is working.
     *
     * It is still worth counting, because "who can take money we cannot yet hand
     * over" is how a backlog starts. But the question is about **the bank the
     * payout run pays into**, not the provider's own destination record: the
     * sweep reads `bank_code` and `account_number` off the business and nothing
     * else, so anything else asked here would report a business as payable that
     * the sweep skips. Read the same fields as
     * {@see PayoutSweepService} for that reason.
     *
     * Null `$bank` means the business never named one. That used to be the
     * `bank_account` lock-out above and is now this, which is the whole shape of
     * the change: a business that has not named a bank can still sell.
     */
    private function payabilityGap(?object $bank): ?string
    {
        if ($bank === null || blank($bank->account_number) || blank($bank->bank_code)) {
            return 'It can take payments, but it has not named a bank account to be paid into.';
        }

        return null;
    }

    /**
     * Whether the platform itself still offers this processor.
     *
     * The platform's switch, not the business's: `payment_methods.is_active` is
     * what {@see PaymentGatewayResolver::platformMethodActive()} reads, and with
     * it off no business on the platform can charge through this provider at all.
     */
    private function platformOffers(string $processor): bool
    {
        return (bool) DB::table('payment_methods')->where('code', $processor)->value('is_active');
    }

    /**
     * The owner's switches, by scope, for the processor being counted.
     *
     * Only rows are read, and only their `is_active` — the rest of a payment-method
     * row carries credentials and configuration that enforcement ignores entirely,
     * because under enforcement the charge is Storify's. What survives is the one
     * thing a row still says on a business's behalf: that its owner wants Storify
     * payments off. An absent row is deliberately **not** recorded here, so the
     * caller can tell "no row" from "a row that says on" — both of which mean
     * chargeable, but only one of which means somebody clicked something.
     *
     * The rows are not filtered by `gateway_account_id` the way they used to be.
     * They had to be then, because a row with no account was a row the platform
     * could not charge through; now the account is exactly the part that no longer
     * exists, and filtering on it would hide every off-switch on the platform.
     *
     * @return array{business: array<int, bool>, store: array<int, bool>}
     */
    private function switchRows(string $processor): array
    {
        $business = DB::table('business_payment_method as bpm')
            ->join('payment_methods as pm', 'pm.id', '=', 'bpm.payment_method_id')
            ->where('pm.code', $processor)
            ->pluck('bpm.is_active', 'bpm.business_id')
            ->map(fn ($active): bool => (bool) $active)
            ->all();

        $store = DB::table('store_payment_method as spm')
            ->join('payment_methods as pm', 'pm.id', '=', 'spm.payment_method_id')
            ->where('pm.code', $processor)
            ->pluck('spm.is_active', 'spm.store_id')
            ->map(fn ($active): bool => (bool) $active)
            ->all();

        // pluck() keys arrive as whatever the driver hands back, and both maps
        // are looked up with a cast int, so the keys are cast once here rather
        // than at every lookup.
        return [
            'business' => array_combine(array_map('intval', array_keys($business)), $business) ?: [],
            'store' => array_combine(array_map('intval', array_keys($store)), $store) ?: [],
        ];
    }

    /**
     * Every store on the platform, grouped by the business that owns it.
     *
     * Ids rather than a count, because the store is the unit that breaks: a
     * business whose own switch is off may still have a store that never
     * inherited it and is fine, and a count of stores would report that store as
     * affected when it is not.
     *
     * @return array<int, array<int, int>>
     */
    private function storeIdsByBusiness(): array
    {
        $grouped = [];

        foreach (DB::table('stores')->get(['id', 'business_id']) as $store) {
            $grouped[(int) $store->business_id][] = (int) $store->id;
        }

        return $grouped;
    }

    private function name(string $processor): string
    {
        return (string) (PaymentGatewayRegistry::get($processor)['name'] ?? $processor);
    }
}
