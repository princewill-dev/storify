<?php

use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The switch that stops money leaving, at one of three widths.
 *
 * Before this table the only way to stop a payout was `businesses.auto_payout`,
 * which had no route, no controller and no command — it could only be set by
 * editing the database. So the answer to "hold this business's payouts while we
 * look into something" was to edit a column and remember to change it back, with
 * no record of who did it, when, or why.
 *
 * A hold is **not** a wallet operation and never touches a balance. It is read in
 * exactly one place — {@see PayoutSweepService::sweep()}
 * — which is what makes it safe by construction: it cannot stop a business being
 * *charged* (the charge path never reads it), it cannot clear or zero what a
 * business is owed (no wallet mutator reads it), and it cannot strand a payout
 * that is already with the provider (the reconciler and the webhook path never
 * read it). A held business's money stays visible and owed until the hold lifts.
 *
 * ## Why 0 sentinels instead of NULLs
 *
 * A hold's scope decides which of the two ids mean anything, and the unique index
 * below has to enforce "one open hold per scope per subject". NULL would break
 * that: MySQL treats NULLs as distinct, so a unique index containing them permits
 * unlimited rows, and the second hold on a suspended business would be written
 * rather than refused. `0` is a foreign-key-shaped value that is not a foreign key,
 * which is why these two columns carry indexes and no `constrained()`.
 *
 * ## The open marker
 *
 * `open_marker` is 1 while the hold is in force and NULL once it is released, and
 * it is the only column in the unique index that changes. Indexing `released_at`
 * directly would have the same NULL problem pointed the other way: it would
 * enforce uniqueness among *released* rows — of which there may legitimately be
 * many — and permit any number of open ones.
 *
 * ## `source`, and why it is in the unique index
 *
 * What placed the hold, so that lifting one does not lift another. A business
 * suspension places a hold automatically, and reactivating the business has to
 * release *that* hold and not a manual hold an operator placed to investigate
 * something else.
 *
 * That only works if the two can be open **at the same time**, so `source` is part
 * of the unique index rather than a label beside it. Without it the index would
 * admit one open hold per subject, the second placement — whichever it was —
 * would be a no-op returning the first, and the consequence would land on
 * reactivation: suspend a business an operator had already held, and activating it
 * would find a row marked `manual`, do nothing, and leave the business unable to be
 * paid with no reason visible anywhere but the reason text. Holding a subject twice
 * for two different reasons is exactly the situation this table exists for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_holds', function (Blueprint $table) {
            $table->id();

            // `system` | `business` | `store`. A string rather than an enum, to
            // match how every other scope-like column in this subsystem is stored:
            // an enum in the schema needs a migration to add a width, and the
            // width is the thing most likely to change.
            $table->string('scope', 16);

            // 0, not NULL — see the class docblock. Never a foreign key, because 0
            // is not a business.
            $table->unsignedBigInteger('business_id')->default(0);
            $table->unsignedBigInteger('store_id')->default(0);

            // Why. Required, because a hold nobody can explain is a hold nobody
            // will remember to lift.
            $table->string('reason', 255);

            // `manual` | `suspension`.
            $table->string('source', 16)->default('manual');

            $table->unsignedBigInteger('placed_by')->nullable();
            $table->timestamp('placed_at');

            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();

            // 1 while open, NULL once released. The column that makes the unique
            // index below do what it says.
            $table->unsignedTinyInteger('open_marker')->nullable();

            $table->timestamps();

            // One open hold per subject **per source** — see the class docblock for
            // why `source` is part of the key rather than a label beside it.
            $table->unique(
                ['scope', 'business_id', 'store_id', 'source', 'open_marker'],
                'payout_holds_open_unique',
            );

            // The read the sweep makes on every candidate, and the console's "what
            // is currently held" list.
            $table->index(['scope', 'business_id', 'store_id'], 'payout_holds_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_holds');
    }
};
