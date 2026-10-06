<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Services\Accounting\LedgerAccountTemplate;
use App\Services\Accounting\LedgerClosingService;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-37 — Accounting settings, closing and opening balances.
 *
 * Rebuilds the legacy settings screen as JSON: the 20 auto-posting account
 * mappings, the one-time opening balance posting, the fiscal period list with
 * close/reopen, and the fiscal year list with year-end close. Money on the
 * wire is integer kobo (`*_kobo`); the SPA converts the naira the user types
 * once, at the edge.
 *
 * Improvements on legacy, called out where they live in the code:
 *  - mapping keys are validated against the template's 20 keys, so a typo no
 *    longer creates a junk mapping row that shadows nothing;
 *  - the mapping picker returns every account with its `is_active` flag (an
 *    inactive account a mapping already points at stays visible instead of
 *    silently showing a blank select);
 *  - the opening balance form accepts kobo integers only, so the one-time
 *    entry can never be off by a rounding cent.
 */
class AccountingSettingsController extends ApiController
{
    use ResolvesManagementContext;

    /** Balance keys posted as debits, in the legacy form's order. */
    private const DEBIT_KEYS = [
        'cash',
        'bank',
        'gateway_clearing',
        'accounts_receivable',
        'inventory',
        'fixed_assets',
    ];

    /** Balance keys posted as credits. */
    private const CREDIT_KEYS = [
        'accounts_payable',
        'tax_payable',
        'owner_equity',
    ];

    /** Human labels for the 20 mapping keys, in the legacy screen's order. */
    private const MAPPING_LABELS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'gateway_clearing' => 'Gateway clearing',
        'accounts_receivable' => 'Accounts receivable',
        'inventory' => 'Inventory',
        'fixed_assets' => 'Fixed assets',
        'accounts_payable' => 'Accounts payable',
        'tax_payable' => 'Tax payable',
        'accrued_liabilities' => 'Accrued liabilities',
        'owner_equity' => "Owner's equity",
        'retained_earnings' => 'Retained earnings',
        'opening_balance_equity' => 'Opening balance equity',
        'sales_income' => 'Sales income',
        'service_charge_income' => 'Service charge income',
        'shipping_income' => 'Shipping income',
        'other_income' => 'Other income',
        'sales_discounts' => 'Sales discounts',
        'cogs' => 'Cost of goods sold',
        'gateway_fees' => 'Gateway fees',
        'default_expense' => 'Default expense',
    ];

    public function __construct(
        private readonly LedgerPostingService $posting,
        private readonly LedgerClosingService $closing,
        private readonly LedgerSetupService $setup,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        // Legacy bootstrapped the books on this screen, so a business that
        // never opened the accounts page still gets its chart and current
        // fiscal year here before the pickers read them.
        $this->setup->ensureForBusiness($businessId);

        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->get();

        $mappings = LedgerMapping::query()
            ->where('business_id', $businessId)
            ->with('account')
            ->get()
            ->keyBy('key');

        $openingEntry = JournalEntry::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', 'like', 'opening:%')
            ->first();

        return $this->ok([
            'accounts' => $accounts->map(fn (LedgerAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'subtype' => $account->subtype,
                'is_active' => (bool) $account->is_active,
                'is_system' => (bool) $account->is_system,
            ])->values()->all(),
            'mappings' => $this->mappingsPayload($mappings),
            'opening_balances' => [
                'posted' => $openingEntry !== null,
                'entry' => $openingEntry ? [
                    'id' => $openingEntry->id,
                    'entry_number' => $openingEntry->entry_number,
                    'entry_date' => $openingEntry->entry_date?->toDateString(),
                    'memo' => $openingEntry->memo,
                ] : null,
            ],
            'periods' => FiscalPeriod::query()
                ->where('business_id', $businessId)
                ->orderByDesc('name')
                ->limit(24)
                ->get()
                ->map(fn (FiscalPeriod $period) => $this->periodPayload($period))
                ->all(),
            'fiscal_years' => FiscalYear::query()
                ->where('business_id', $businessId)
                ->orderByDesc('name')
                ->limit(6)
                ->get()
                ->map(fn (FiscalYear $year) => $this->yearPayload($year))
                ->all(),
        ]);
    }

    public function updateMappings(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
        ]);

        // Legacy wrote a row for whatever key arrived; a typo shadowed the
        // real key and left the event posting to the old default.
        $unknown = array_diff(array_keys($validated['mappings']), array_keys(LedgerAccountTemplate::mappings()));

        if (! empty($unknown)) {
            throw ValidationException::withMessages([
                'mappings' => 'Unknown mapping key(s): '.implode(', ', $unknown).'.',
            ]);
        }

        DB::transaction(function () use ($businessId, $validated) {
            foreach ($validated['mappings'] as $key => $accountId) {
                LedgerMapping::updateOrCreate(
                    ['business_id' => $businessId, 'key' => $key],
                    ['ledger_account_id' => $accountId]
                );
            }
        });

        Log::info('api.management.accounting_mappings_updated', [
            'user_id' => $this->user($request)->id,
            'keys' => array_keys($validated['mappings']),
        ]);

        $mappings = LedgerMapping::query()
            ->where('business_id', $businessId)
            ->with('account')
            ->get()
            ->keyBy('key');

        return $this->ok(['mappings' => $this->mappingsPayload($mappings)], 'Account mappings updated.');
    }

    public function storeOpeningBalances(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        // One-time by design: once the opening entry exists the form is a
        // read-only confirmation card, and posting again is refused outright.
        if (JournalEntry::query()->where('business_id', $businessId)->where('idempotency_key', 'like', 'opening:%')->exists()) {
            return $this->error('Opening balances have already been posted.');
        }

        $rules = [
            'as_of' => ['required', 'date'],
        ];

        foreach ([...self::DEBIT_KEYS, ...self::CREDIT_KEYS] as $key) {
            $rules[$key.'_kobo'] = ['nullable', 'integer', 'min:0'];
        }

        $validated = $request->validate($rules);

        $balances = [];

        foreach (self::DEBIT_KEYS as $key) {
            $amount = (int) ($validated[$key.'_kobo'] ?? 0);

            if ($amount > 0) {
                $balances[$key] = $amount;
            }
        }

        foreach (self::CREDIT_KEYS as $key) {
            $amount = (int) ($validated[$key.'_kobo'] ?? 0);

            if ($amount > 0) {
                // Credits travel through the posting service as negatives.
                $balances[$key] = -$amount;
            }
        }

        if (empty($balances)) {
            return $this->error('Enter at least one opening balance.');
        }

        try {
            $entry = $this->posting->postOpeningBalances($businessId, $balances, $validated['as_of'], $user->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        if (! $entry) {
            return $this->error('Enter at least one opening balance.');
        }

        Log::info('api.management.accounting_opening_balances_posted', [
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'keys' => array_keys($balances),
        ]);

        return $this->ok([
            'entry' => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
            ],
            'opening_balances' => [
                'posted' => true,
                'entry' => [
                    'id' => $entry->id,
                    'entry_number' => $entry->entry_number,
                    'entry_date' => $entry->entry_date?->toDateString(),
                    'memo' => $entry->memo,
                ],
            ],
        ], 'Opening balances posted.', 201);
    }

    public function closePeriod(Request $request, FiscalPeriod $period): JsonResponse
    {
        $this->authorizePeriod($request, $period);

        if (! $period->isOpen()) {
            return $this->error('This period is already closed.');
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->user($request)->id,
        ]);

        Log::info('api.management.accounting_period_closed', [
            'user_id' => $this->user($request)->id,
            'period_id' => $period->id,
        ]);

        return $this->ok(['period' => $this->periodPayload($period->fresh())], "Period {$period->name} closed.");
    }

    public function reopenPeriod(Request $request, FiscalPeriod $period): JsonResponse
    {
        $this->authorizePeriod($request, $period);

        if ($period->isOpen()) {
            return $this->error('This period is already open.');
        }

        // Deliberately allowed even when the parent year is closed: legacy
        // had no year-reopen action, so refusing here would strand a period
        // closed by mistake with no way back.
        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        Log::info('api.management.accounting_period_reopened', [
            'user_id' => $this->user($request)->id,
            'period_id' => $period->id,
        ]);

        return $this->ok(['period' => $this->periodPayload($period->fresh())], "Period {$period->name} reopened.");
    }

    public function closeYear(Request $request, int $year): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        $fiscalYear = FiscalYear::query()
            ->where('business_id', $businessId)
            ->where('name', (string) $year)
            ->first();

        if (! $fiscalYear) {
            return $this->error("Fiscal year {$year} does not exist for these books.", 404);
        }

        try {
            $entry = $this->closing->closeYear($businessId, $year, $user->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        Log::info('api.management.accounting_year_closed', [
            'user_id' => $user->id,
            'year' => $year,
            'entry_id' => $entry?->id,
        ]);

        return $this->ok([
            'entry' => $entry ? [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'memo' => $entry->memo,
            ] : null,
            'fiscal_year' => $this->yearPayload($fiscalYear->fresh()),
        ], $entry
            ? "Fiscal year {$year} closed — net result transferred to retained earnings."
            : "Fiscal year {$year} closed.");
    }

    /**
     * @param  Collection<string, LedgerMapping>  $mappings
     * @return array<int, array<string, mixed>>
     */
    private function mappingsPayload($mappings): array
    {
        $rows = [];

        foreach (LedgerAccountTemplate::mappings() as $key => $defaultCode) {
            /** @var LedgerMapping|null $mapping */
            $mapping = $mappings->get($key);

            $rows[] = [
                'key' => $key,
                'label' => self::MAPPING_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'account_id' => $mapping?->ledger_account_id,
                'account_code' => $mapping?->account?->code,
                'account_name' => $mapping?->account?->name,
                'default_code' => $defaultCode,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(FiscalPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'status' => $period->status,
            'closed_at' => $period->closed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function yearPayload(FiscalYear $year): array
    {
        return [
            'id' => $year->id,
            'name' => $year->name,
            'start_date' => $year->start_date?->toDateString(),
            'end_date' => $year->end_date?->toDateString(),
            'status' => $year->status,
        ];
    }

    private function authorizePeriod(Request $request, FiscalPeriod $period): void
    {
        if ((int) $period->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this fiscal period.');
        }
    }
}
