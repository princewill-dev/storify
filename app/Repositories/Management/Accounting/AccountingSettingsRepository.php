<?php

namespace App\Repositories\Management\Accounting;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use Illuminate\Support\Collection;

/**
 * WS-37 — accounting settings queries and single-statement persistence.
 *
 * Everything AccountingSettingsController used to build inline: the chart
 * list, the keyed mapping rows both the settings screen and a save response
 * read, the opening-entry probe, the fiscal period/year lists and the year
 * lookup behind the close action.
 *
 * Reads and writes only: transaction boundaries live in
 * AccountingSettingsService and abort() refusals at the HTTP edge. Every
 * method is scoped by business_id here rather than at the call site, so a
 * missing tenant filter cannot leak another business's books.
 *
 * business_id stays nullable: a platform admin carries null, and null is the
 * platform books' scope (business_id IS NULL), exactly as the inline queries
 * treated it. Casting null to 0 would point every query at a different set of
 * books, so the value is passed through untouched.
 */
final class AccountingSettingsRepository
{
    /**
     * The business chart, in code order — what the mapping picker shows.
     *
     * @return Collection<int, LedgerAccount>
     */
    public function accountsForBusiness(?int $businessId): Collection
    {
        return LedgerAccount::query()
            ->where('business_id', $businessId)
            ->orderBy('code')
            ->get();
    }

    /**
     * Mapping rows keyed by their template key, with the account eager
     * loaded. Read by the settings screen and again after every save, so the
     * picker can never disagree with the save response.
     *
     * @return Collection<string, LedgerMapping>
     */
    public function mappingsByKey(?int $businessId): Collection
    {
        return LedgerMapping::query()
            ->where('business_id', $businessId)
            ->with('account')
            ->get()
            ->keyBy('key');
    }

    /**
     * The one-time opening entry, if it has been posted — the settings
     * screen's confirmation card.
     */
    public function openingEntry(?int $businessId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', 'like', 'opening:%')
            ->first();
    }

    /**
     * Existence probe for the "already posted" guard.
     */
    public function hasOpeningEntry(?int $businessId): bool
    {
        return JournalEntry::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', 'like', 'opening:%')
            ->exists();
    }

    /**
     * @return Collection<int, FiscalPeriod>
     */
    public function periodsForBusiness(?int $businessId, int $limit): Collection
    {
        return FiscalPeriod::query()
            ->where('business_id', $businessId)
            ->orderByDesc('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    public function fiscalYearsForBusiness(?int $businessId, int $limit): Collection
    {
        return FiscalYear::query()
            ->where('business_id', $businessId)
            ->orderByDesc('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Locate a year by name for the close action's 404 — a missing year is
     * refused before the closing service gets a chance to throw.
     */
    public function findYearByName(?int $businessId, string $name): ?FiscalYear
    {
        return FiscalYear::query()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->first();
    }

    /**
     * Upsert one auto-posting mapping. The business id is part of the lookup
     * key, so a save can never repoint another business's mapping row.
     */
    public function saveMapping(?int $businessId, string $key, int $accountId): void
    {
        LedgerMapping::updateOrCreate(
            ['business_id' => $businessId, 'key' => $key],
            ['ledger_account_id' => $accountId]
        );
    }
}
