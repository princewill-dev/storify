<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair the customer rows that were created with no business.
 *
 * Registration never set `business_id`, so every account made through the
 * storefront before this change is stored with a NULL one and belongs to
 * nobody. Only the safe bucket is repaired here: a row whose orders all belong
 * to a single business, where that business has no customer with the same
 * email already.
 *
 * Everything else is left alone and counted. A row with no orders carries no
 * tenant signal anywhere — inferring one would be a guess, and guessing is
 * exactly the defect being repaired. Those rows stay NULL and are consequently
 * unloginable through any storefront, which is the correct outcome rather than
 * a loss: the person can still check out again and be matched by
 * `(business_id, email)`.
 *
 * The merge cases — orders spanning two businesses, or a row that collides
 * with an existing account — are reported by `php artisan
 * customers:reconcile-orphans` and resolved by hand. They are NOT resolved
 * here: reassigning orders and deleting a customer row is not something that
 * should run unattended on a manual deploy.
 *
 * `DB::table` rather than the `Customer` model throughout. A migration that
 * reads a model is a migration whose behaviour changes when the model does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $counts = [
            'backfilled' => 0,
            'collision' => 0,
            'no_orders' => 0,
            'multi_business' => 0,
            'no_email' => 0,
        ];

        DB::table('customers')
            ->whereNull('business_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    if (empty($row->email)) {
                        $counts['no_email']++;

                        continue;
                    }

                    $businessIds = DB::table('orders')
                        ->where('customer_id', $row->id)
                        ->whereNotNull('business_id')
                        ->distinct()
                        ->pluck('business_id');

                    if ($businessIds->isEmpty()) {
                        $counts['no_orders']++;

                        continue;
                    }

                    if ($businessIds->count() > 1) {
                        $counts['multi_business']++;

                        continue;
                    }

                    $businessId = (int) $businessIds->first();

                    // MySQL allows repeated NULLs in a unique index, so
                    // several orphan rows can share an email. Writing a
                    // business onto one of them would collide with the
                    // (business_id, email) index if an account already holds
                    // that pair — check before writing, or the migration dies
                    // halfway through a manual deploy.
                    $taken = DB::table('customers')
                        ->where('business_id', $businessId)
                        ->where('email', $row->email)
                        ->where('id', '!=', $row->id)
                        ->exists();

                    if ($taken) {
                        $counts['collision']++;

                        continue;
                    }

                    DB::table('customers')
                        ->where('id', $row->id)
                        ->update(['business_id' => $businessId]);

                    $counts['backfilled']++;
                }
            });

        Log::info('customers.business_backfill', $counts);

        if ($counts['collision'] > 0 || $counts['multi_business'] > 0) {
            Log::warning('customers.business_backfill_needs_attention', [
                'hint' => 'Run `php artisan customers:reconcile-orphans` and merge by hand.',
                'collision' => $counts['collision'],
                'multi_business' => $counts['multi_business'],
            ]);
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would mean nulling the business on rows that are correct,
     * turning working accounts back into unreachable ones. There is nothing to
     * restore to: the previous state was the bug.
     */
    public function down(): void {}
};
