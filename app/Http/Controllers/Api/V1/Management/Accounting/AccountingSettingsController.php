<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\StoreOpeningBalancesRequest;
use App\Http\Requests\Management\Accounting\UpdateMappingsRequest;
use App\Http\Resources\Management\Accounting\FiscalPeriodResource;
use App\Http\Resources\Management\Accounting\FiscalYearResource;
use App\Http\Resources\Management\Accounting\JournalEntryBriefResource;
use App\Http\Resources\Management\Accounting\MappingResource;
use App\Http\Resources\Management\Accounting\SettingsAccountResource;
use App\Models\FiscalPeriod;
use App\Repositories\Management\Accounting\AccountingSettingsRepository;
use App\Services\Access\TenantGuard;
use App\Services\Accounting\AccountingSettingsService;
use App\Services\Accounting\LedgerClosingService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
 *    longer creates a junk mapping row that shadows nothing
 *    (UpdateMappingsRequest);
 *  - the mapping picker returns every account with its `is_active` flag (an
 *    inactive account a mapping already points at stays visible instead of
 *    silently showing a blank select) (SettingsAccountResource);
 *  - the opening balance form accepts kobo integers only, so the one-time
 *    entry can never be off by a rounding cent (StoreOpeningBalancesRequest).
 *
 * The controller keeps the HTTP shape only — statuses, message strings and
 * the envelope. Validation lives in the Management\Accounting FormRequests,
 * queries/persists in AccountingSettingsRepository, the mapping write's
 * transaction and the opening balance sign mapping in
 * AccountingSettingsService, and response shaping in the Management\Accounting
 * resources. The fiscal-period tenant check goes through TenantGuard, so a
 * foreign period is a 403 before the state guards run.
 */
class AccountingSettingsController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly LedgerSetupService $setup,
        private readonly LedgerClosingService $closing,
        private readonly AccountingSettingsRepository $repository,
        private readonly AccountingSettingsService $service,
        private readonly TenantGuard $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Left uncast on purpose: a platform admin's null business_id is the
        // platform books' business_id (null) here, exactly as before the
        // refactor — (int) would silently turn every IS NULL into = 0.
        $businessId = $this->user($request)->business_id;

        // Legacy bootstrapped the books on this screen, so a business that
        // never opened the accounts page still gets its chart and current
        // fiscal year here before the pickers read them.
        $this->setup->ensureForBusiness($businessId);

        $accounts = $this->repository->accountsForBusiness($businessId);
        $mappings = $this->repository->mappingsByKey($businessId);
        $openingEntry = $this->repository->openingEntry($businessId);

        return $this->ok([
            'accounts' => SettingsAccountResource::collection($accounts)->resolve(),
            'mappings' => MappingResource::rows($mappings),
            'opening_balances' => [
                'posted' => $openingEntry !== null,
                'entry' => JournalEntryBriefResource::brief($openingEntry),
            ],
            'periods' => FiscalPeriodResource::collection($this->repository->periodsForBusiness($businessId, 24))->resolve(),
            'fiscal_years' => FiscalYearResource::collection($this->repository->fiscalYearsForBusiness($businessId, 6))->resolve(),
        ]);
    }

    public function updateMappings(UpdateMappingsRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        // The request has already refused any key outside the template's 20
        // (legacy wrote a row for whatever key arrived); only the write is
        // left.
        $this->service->saveMappings($businessId, $request->validated()['mappings'], (int) $user->id);

        return $this->ok(
            ['mappings' => MappingResource::rows($this->repository->mappingsByKey($businessId))],
            'Account mappings updated.'
        );
    }

    public function storeOpeningBalances(StoreOpeningBalancesRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        // One-time by design: once the opening entry exists the form is a
        // read-only confirmation card, and posting again is refused outright.
        if ($this->repository->hasOpeningEntry($businessId)) {
            return $this->error('Opening balances have already been posted.');
        }

        try {
            $entry = $this->service->storeOpeningBalances($businessId, $request->validated(), (int) $user->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        if (! $entry) {
            return $this->error('Enter at least one opening balance.');
        }

        $entryPayload = JournalEntryBriefResource::brief($entry);

        return $this->ok([
            'entry' => $entryPayload,
            'opening_balances' => [
                'posted' => true,
                'entry' => $entryPayload,
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

        return $this->ok(['period' => FiscalPeriodResource::make($period->fresh())->resolve()], "Period {$period->name} closed.");
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

        return $this->ok(['period' => FiscalPeriodResource::make($period->fresh())->resolve()], "Period {$period->name} reopened.");
    }

    public function closeYear(Request $request, int $year): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        $fiscalYear = $this->repository->findYearByName($businessId, (string) $year);

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
            'entry' => JournalEntryBriefResource::brief($entry),
            'fiscal_year' => FiscalYearResource::make($fiscalYear->fresh())->resolve(),
        ], $entry
            ? "Fiscal year {$year} closed — net result transferred to retained earnings."
            : "Fiscal year {$year} closed.");
    }

    private function authorizePeriod(Request $request, FiscalPeriod $period): void
    {
        $this->tenant->authorizeBusiness($period, $this->user($request), 'You do not have access to this fiscal period.');
    }
}
