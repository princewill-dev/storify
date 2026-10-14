<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop requiring a store's support contact to be unique across the platform.
 *
 * ## The rule this removes, and the model it belonged to
 *
 * `2025_11_16_143500_add_unique_vendor_store_contacts.php` made
 * `stores.support_email` and `stores.support_phone` unique, in the same breath
 * as making a seller's own phone unique. That was a coherent pair while one
 * seller kept one store: a contact belonged to the seller, and no single seller
 * could hold two of them. That model is gone — a business runs several stores
 * by design, and the store limit the plans sell is the whole point — but this
 * index came across unchanged. It outlived its reason.
 *
 * What it produced is a business that could not open a second store with the
 * same phone number on it, which is the ordinary case rather than the odd one:
 * the shops are run by the same people, on the same line, and the create form
 * offers the owner's own number as the default. The first store took it and the
 * second was refused.
 *
 * ## Why the index, and not only the validation rule
 *
 * `CreateStoreRequest` carried a matching `unique:stores,support_phone` rule,
 * and that is what answered with "The support phone has already been taken." —
 * but it was the only path that enforced this. The store settings form, the
 * admin store forms and the admin settings form all accepted a duplicate and
 * then met the index, surfacing a QueryException as a 500 with nothing in it
 * anybody could act on. Deleting the rule and leaving the index would have
 * moved the failure instead of fixing it.
 *
 * Uniqueness is dropped outright rather than rescoped to the business. Scoping
 * would still refuse the case this exists for — one business, several stores,
 * one phone — and the index was never load-bearing for reads: nothing resolves
 * a store by its contact details, so there is nothing left for it to protect.
 *
 * Existing rows are unaffected. Where the index held, no duplicate exists to be
 * revealed, so nothing is backfilled and nothing is written.
 */
return new class extends Migration
{
    /**
     * The two indexes `2025_11_16_143500` created. Both were given explicit
     * names, and neither matches the convention Laravel generates from the
     * columns, so they are named here rather than derived.
     */
    private const INDEXES = [
        'stores_support_email_unique',
        'stores_support_phone_unique',
    ];

    public function up(): void
    {
        $present = array_values(array_filter(
            self::INDEXES,
            fn (string $index): bool => $this->indexExists($index),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) use ($present) {
            foreach ($present as $index) {
                $table->dropUnique($index);
            }
        });
    }

    /**
     * Deliberately empty.
     *
     * Restoring the indexes can *fail* on a database that has since put one
     * phone number on two stores, which is precisely the state this migration
     * exists to allow — a rollback that refuses to run is worse than one that
     * leaves the columns alone. Nothing was recorded that could be unrecorded;
     * the uniqueness was a constraint, and dropping it lost no data.
     */
    public function down(): void
    {
        //
    }

    private function indexExists(string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['stores', $index]
        );

        return $row !== null && (int) $row->cnt > 0;
    }
};
