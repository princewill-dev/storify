<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bank account a business's sales are paid into.
 *
 * Under a system payment processor the split happens at source: the provider
 * separates Storify's commission from the business's share and settles the share
 * to the business's own bank. Naming that account is the *only* thing the
 * business is asked for — the subaccount or connected account the provider needs
 * is Storify's own plumbing, built from this row.
 *
 * Which is why it is a column of its own rather than a reading of
 * `store_banks.is_primary`. That flag already means something else: it drives
 * bank transfer and reconciliation, it is settable by a business that never
 * intended to receive splits there, and a business holding one would silently
 * pass a gate it was never asked about.
 *
 * It is also deliberately **not** scoped to the provider. The bank does not
 * change when an admin switches the default processor from one to the other —
 * what changes is who pays it — so keeping the destination independent of the
 * provider is what stops a switch from re-asking every business for details they
 * have already given.
 *
 * `payout_entity_type` is the other half, and it is knowledge rather than a
 * preference: a provider that requires the business to be registered as a
 * company or as an individual takes that answer once, will not accept a change
 * afterwards, and gets it from the owner rather than from a guess. Storing the
 * answer here means a later switch of processor re-uses the answer instead of
 * asking for it again — or worse, defaulting it.
 *
 * ## The backfill
 *
 * Best effort, and honest about what it can match. A business that registered a
 * payout account before this migration holds the destination only in masked
 * form (`last4` and the bank's name, never the account number), so the row is
 * recovered from those two facts and left NULL when they match nothing. NULL is
 * the safe outcome: the business is asked once on its next visit, and because
 * `PaymentGatewayService::registerPayoutAccount()` reuses the account that
 * already exists at the provider, answering re-registers the same account's
 * destination rather than opening a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('payout_bank_id')
                ->nullable()
                ->after('auto_payout')
                ->constrained('store_banks')
                ->nullOnDelete();

            $table->string('payout_entity_type', 20)->nullable()->after('payout_bank_id');
        });

        $this->backfillFromMaskedDestinations();
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Dropped before the column it points at, so the constraint never
            // outlives its own foreign key.
            $table->dropForeign(['payout_bank_id']);
            $table->dropColumn(['payout_bank_id', 'payout_entity_type']);
        });
    }

    /**
     * Recover the pointer for businesses that already registered a destination.
     *
     * Read in PHP rather than in one clever join: the match is on masked values
     * that have to be compared loosely (the provider's `bank_name` may be a code
     * or a missing field), and a migration that silently mismatches is a
     * migration nobody can reason about afterwards. Rows that match nothing are
     * counted and logged rather than guessed at.
     */
    private function backfillFromMaskedDestinations(): void
    {
        $rows = DB::table('business_payment_method as bpm')
            ->whereNotNull('bpm.gateway_account_id')
            ->where('bpm.gateway_account_id', '!=', '')
            ->get(['bpm.business_id', 'bpm.config']);

        $unmatched = 0;

        foreach ($rows as $row) {
            $businessId = (int) $row->business_id;

            // Already set by an earlier row for the same business — one pointer
            // per business, and the first match wins.
            if (DB::table('businesses')->where('id', $businessId)->whereNotNull('payout_bank_id')->exists()) {
                continue;
            }

            $config = json_decode((string) ($row->config ?? ''), true);
            $destination = is_array($config) ? ($config['managed']['payout_destination'] ?? null) : null;

            if (! is_array($destination)) {
                continue;
            }

            $last4 = $destination['last4'] ?? null;

            if (! is_string($last4) || $last4 === '') {
                continue;
            }

            $candidates = DB::table('store_banks')
                ->where('business_id', $businessId)
                ->where('account_number', 'like', '%'.$last4)
                ->get(['id', 'bank_name']);

            $match = $candidates->first(function (object $candidate) use ($destination): bool {
                $maskedName = $destination['bank_name'] ?? null;

                // A provider that reports no bank name still leaves the account
                // number's last four, which is a strong enough signal on its
                // own for a single match.
                return ! is_string($maskedName) || $maskedName === ''
                    || $candidate->bank_name === null
                    || strcasecmp((string) $candidate->bank_name, $maskedName) === 0;
            });

            if ($match === null) {
                $unmatched++;

                continue;
            }

            // The entity type is *not* recoverable here, and is left NULL on
            // purpose: the provider never reported it back, and the business is
            // the only one entitled to decide it. It is asked for the first time
            // the owner registers or re-registers a destination.
            DB::table('businesses')->where('id', $businessId)->update([
                'payout_bank_id' => $match->id,
            ]);
        }

        if ($unmatched > 0) {
            // Not an error. These businesses are asked once, and answering
            // re-registers the account they already have.
            logger()->info('payout_bank.backfill_unmatched', ['businesses' => $unmatched]);
        }
    }
};
