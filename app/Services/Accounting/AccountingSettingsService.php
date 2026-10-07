<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Repositories\Management\Accounting\AccountingSettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-37 — accounting settings workflows.
 *
 * The steps that belong to the domain rather than the HTTP edge, extracted
 * from AccountingSettingsController: the mapping save's transaction (all
 * submitted rows land or none does) and the opening balance form's sign
 * mapping, which sends credit keys to the posting service as negatives.
 *
 * Status codes, message strings, guard order and the "already posted"
 * refusal stay in the controller; this class never touches the response. It
 * does own the audit lines for the two writes it performs, exactly as the
 * platform accounting service does for the same operations.
 */
final class AccountingSettingsService
{
    /** Balance keys posted as debits, in the legacy form's order. */
    public const DEBIT_KEYS = [
        'cash',
        'bank',
        'gateway_clearing',
        'accounts_receivable',
        'inventory',
        'fixed_assets',
    ];

    /** Balance keys posted as credits. */
    public const CREDIT_KEYS = [
        'accounts_payable',
        'tax_payable',
        'owner_equity',
    ];

    public function __construct(
        private readonly LedgerPostingService $posting,
        private readonly AccountingSettingsRepository $repository,
    ) {}

    /**
     * Persist every submitted mapping in one transaction, so a mid-list
     * failure cannot leave half the event keys repointed.
     *
     * @param  array<string, int|string>  $mappings  validated mappings payload
     */
    public function saveMappings(?int $businessId, array $mappings, int $userId): void
    {
        DB::transaction(function () use ($businessId, $mappings) {
            foreach ($mappings as $key => $accountId) {
                $this->repository->saveMapping($businessId, (string) $key, (int) $accountId);
            }
        });

        Log::info('api.management.accounting_mappings_updated', [
            'user_id' => $userId,
            'keys' => array_keys($mappings),
        ]);
    }

    /**
     * Build the opening balance set from the validated unsigned form input
     * and post it through the ledger. Credit keys travel through the posting
     * service as negatives. Returns null when no amount was entered — the
     * controller owns the message for that case.
     *
     * @param  array<string, mixed>  $data  validated StoreOpeningBalancesRequest data
     */
    public function storeOpeningBalances(?int $businessId, array $data, int $userId): ?JournalEntry
    {
        $balances = [];

        foreach (self::DEBIT_KEYS as $key) {
            $amount = (int) ($data[$key.'_kobo'] ?? 0);

            if ($amount > 0) {
                $balances[$key] = $amount;
            }
        }

        foreach (self::CREDIT_KEYS as $key) {
            $amount = (int) ($data[$key.'_kobo'] ?? 0);

            if ($amount > 0) {
                // Credits travel through the posting service as negatives.
                $balances[$key] = -$amount;
            }
        }

        if (empty($balances)) {
            return null;
        }

        $entry = $this->posting->postOpeningBalances($businessId, $balances, $data['as_of'], $userId);

        if ($entry) {
            Log::info('api.management.accounting_opening_balances_posted', [
                'user_id' => $userId,
                'entry_id' => $entry->id,
                'keys' => array_keys($balances),
            ]);
        }

        return $entry;
    }
}
