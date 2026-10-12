<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Payments\Data\ManagedAccount;
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
 * The switch is the most consequential thing in the admin console and the least
 * reversible in its consequences: under enforcement a business whose sales have
 * nowhere to be paid out to **takes no payments at all**, including cash at the
 * till. An admin clicking through a confirmation dialog that does not say how
 * many businesses that is has not been told what they are doing.
 *
 * So this answers, ahead of the click: how many businesses would stop being able
 * to sell, which ones, why each of them, and how many stores sit behind them.
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
 * ## Why it does not use the resolver
 *
 * {@see PaymentGatewayResolver} is the authority on
 * whether a store can charge, and asking it here would be the surest way of
 * agreeing with it. It is also the wrong tool: it resolves one store at a time,
 * and a platform-wide list would be one query per store per provider.
 *
 * What this does instead is read the same **facts** the resolver reads — the
 * account row, its stored `managed` state, the row's own switch — through
 * {@see ManagedAccount}, which is the same class the resolver uses to decide
 * `canCharge()` and `canBePaidOut()`. It makes **no provider calls**: a
 * pre-flight that took two minutes of Bachs API time would be a pre-flight
 * nobody runs, and every fact needed here is already stored on the row.
 *
 * ## Two lists, one of them short
 *
 * Businesses that would break are listed in full — they are the ones somebody
 * has to act on, and there are meant to be few. Businesses that would keep
 * working are counted and not listed: a platform with ten thousand businesses
 * cannot render that list, and there is nothing to do about any of them.
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
        // one at a time is a list they will not act on. `payout_bank_id` rides
        // along because it is what decides *which* sentence each affected
        // business gets — see {@see self::problemFor()}.
        $businesses = DB::table('businesses')->get(['id', 'business_code', 'name', 'payout_bank_id']);

        // One query for every store on the platform, rather than one per
        // business: this runs while an admin is waiting for a dialog to open,
        // and the per-business shape would be a query per business per scope.
        $storesByBusiness = $this->storeIdsByBusiness();
        $storesTotal = array_sum(array_map('count', $storesByBusiness));

        $lockedOut = [];
        $lockedOutStores = 0;

        // Broken down by what an admin would do about it, which is the only
        // distinction that makes the count actionable: businesses to go and ask
        // for a bank account, and businesses whose setup is simply not finished.
        $counts = ['bank_account' => 0, 'setup' => 0, 'connection' => 0, 'destination' => 0];

        // Nothing is counted when there is no processor to count against — the
        // switch is off, and no candidate was named. Skipping the pass keeps that
        // case free of queries as well as of invented answers.
        if ($countedFor !== null) {
            $accounts = $this->accountsByBusiness($countedFor);

            foreach ($businesses as $business) {
                $businessId = (int) $business->id;
                $storeIds = $storesByBusiness[$businessId] ?? [];
                $rows = $accounts[$businessId] ?? [];

                $businessRow = null;
                $storeRows = [];

                foreach ($rows as $row) {
                    if ($row->store_id === null) {
                        $businessRow = $row;
                    } else {
                        $storeRows[(int) $row->store_id] = $row;
                    }
                }

                $hasBank = $business->payout_bank_id !== null;
                $problem = $this->problemFor($businessRow, $countedFor, $hasBank);

                // A store's own usable account is what saves it when the
                // business's is unusable, and vice versa — the resolver prefers
                // the store's row and falls back to the business's, so a store
                // breaks only when neither works.
                $broken = 0;

                foreach ($storeIds as $storeId) {
                    $row = $storeRows[$storeId] ?? $businessRow;

                    if ($this->problemFor($row, $countedFor, $hasBank)['reason'] !== null) {
                        $broken++;
                    }
                }

                $lockedOutStores += $broken;

                if ($problem['reason'] === null && $broken === 0) {
                    continue;
                }

                // The business's own state decides the sentence; when the
                // business is fine and only some of its stores are not, the
                // sentence says exactly that instead of blaming a bank account
                // the business has already named.
                $kind = $problem['reason'] !== null ? $problem['kind'] : 'connection';

                $counts[$kind] = ($counts[$kind] ?? 0) + 1;

                $lockedOut[] = [
                    'business_id' => $businessId,
                    'business_code' => $business->business_code,
                    'name' => (string) $business->name,
                    'stores' => count($storeIds),
                    'stores_locked_out' => $broken,
                    'account_id' => $businessRow->gateway_account_id ?? null,
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
            'processors' => $this->processors(),
            // What the counts below answer: the processor asked about, or the
            // one in force. Carried so a panel can label the numbers instead of
            // leaving an admin to guess which scenario they describe.
            'counted_for' => $countedFor,
            'counted_for_name' => $countedFor === null ? null : $this->name($countedFor),
            'businesses_total' => $businesses->count(),
            'businesses_locked_out' => count($lockedOut),
            // The breakdown an admin acts on: who has to name a bank account,
            // and who is simply waiting for their payment account to be set up.
            'businesses_without_bank_account' => $counts['bank_account'],
            'businesses_awaiting_setup' => $counts['setup'],
            'stores_total' => $storesTotal,
            'stores_locked_out' => $lockedOutStores,
            'businesses' => $lockedOut,
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
     * so the registry stays the one place a provider gains or loses an
     * on-behalf path.
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
     * ## Why the sentence is about a bank account
     *
     * The provider's own view of a business's destination — a subaccount, a
     * connected account — is Storify's plumbing. The business was never asked
     * about it and cannot see it, and reporting it as *their* missing
     * "settlement account" is what an admin read as the platform blaming the
     * wrong party for something they had never been asked to do. What the
     * business is actually asked for is one thing: the bank account its sales
     * are paid into. So that is what this names, and no provider is named at all.
     *
     * The two states differ by who has to act, which is why they are two
     * sentences rather than one: a business with no bank account named is a
     * business somebody has to go and ask, and a business that has named one but
     * holds no account at *this* processor is one whose setup has not caught up
     * — which is what it looks like right after an admin changes the processor.
     *
     * The ordering below is the same as
     * {@see PaymentGatewayResolver}'s enforced reason, so an admin reading one
     * thing while the owner reads another is not possible. The wording differs
     * only in person: this speaks about a business, that speaks to one.
     *
     * @return array{reason: ?string, kind: string}
     */
    private function problemFor(?object $row, string $processor, bool $hasBank): array
    {
        if ($row === null) {
            return $hasBank
                ? ['reason' => 'Its bank account is on file, but its payment account has not been set up yet.', 'kind' => 'setup']
                : ['reason' => 'No bank account has been set for its payments to be paid into.', 'kind' => 'bank_account'];
        }

        if (! (bool) $row->is_active) {
            return ['reason' => 'Payments are switched off for this connection.', 'kind' => 'connection'];
        }

        $account = ManagedAccount::fromConfig($this->managedState($row));

        if (! $account->canCharge($processor)) {
            return ['reason' => 'Its payment account is not ready to take payments.', 'kind' => 'connection'];
        }

        if (! $account->canBePaidOut()) {
            return ['reason' => 'Its payment account has no usable bank destination yet.', 'kind' => 'destination'];
        }

        return ['reason' => null, 'kind' => 'ready'];
    }

    /**
     * Every account row on the selected processor, grouped by business.
     *
     * Both scopes in one query each, because a store with its own account is a
     * separate row and therefore a separate answer — the whole point of the
     * check is that some of a business's stores may break while others do not.
     *
     * Rows with no account id are excluded: a connection without one is a
     * business's own keys, which enforcement ignores entirely, and counting one
     * as an account the platform can charge through would report a business as
     * safe when nothing of its money has anywhere to go.
     *
     * Note what is *not* read here: the business's own bank account. That decides
     * the sentence a broken business gets, not whether it is broken, and keeping
     * the two apart is deliberate — see {@see self::problemFor()}.
     *
     * @return array<int, array<int, object>>
     */
    private function accountsByBusiness(string $processor): array
    {
        $businessRows = DB::table('business_payment_method as bpm')
            ->join('payment_methods as pm', 'pm.id', '=', 'bpm.payment_method_id')
            ->where('pm.code', $processor)
            ->whereNotNull('bpm.gateway_account_id')
            ->where('bpm.gateway_account_id', '!=', '')
            ->get(['bpm.business_id', 'bpm.gateway_account_id', 'bpm.is_active', 'bpm.config'])
            ->map(fn ($row) => (object) [
                'business_id' => (int) $row->business_id,
                'gateway_account_id' => (string) $row->gateway_account_id,
                'is_active' => (bool) $row->is_active,
                'config' => $row->config,
                'store_id' => null,
            ]);

        $storeRows = DB::table('store_payment_method as spm')
            ->join('payment_methods as pm', 'pm.id', '=', 'spm.payment_method_id')
            ->join('stores as s', 's.id', '=', 'spm.store_id')
            ->where('pm.code', $processor)
            ->whereNotNull('spm.gateway_account_id')
            ->where('spm.gateway_account_id', '!=', '')
            ->get(['s.business_id', 'spm.store_id', 'spm.gateway_account_id', 'spm.is_active', 'spm.config'])
            ->map(fn ($row) => (object) [
                'business_id' => (int) $row->business_id,
                'gateway_account_id' => (string) $row->gateway_account_id,
                'is_active' => (bool) $row->is_active,
                'config' => $row->config,
                'store_id' => (int) $row->store_id,
            ]);

        $grouped = [];

        foreach ($businessRows->merge($storeRows) as $row) {
            $grouped[$row->business_id][] = $row;
        }

        return $grouped;
    }

    /**
     * Every store on the platform, grouped by the business that owns it.
     *
     * Ids rather than a count, because the store is the unit that breaks: a
     * business whose own account is unusable may still have a store that banks
     * separately and is fine, and a count of stores would report that store as
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

    /**
     * @return array<string, mixed>|null
     */
    private function managedState(object $row): ?array
    {
        $config = json_decode((string) ($row->config ?? ''), true);
        $managed = is_array($config) ? ($config['managed'] ?? null) : null;

        return is_array($managed) ? $managed : null;
    }

    private function name(string $processor): string
    {
        return (string) (PaymentGatewayRegistry::get($processor)['name'] ?? $processor);
    }
}
