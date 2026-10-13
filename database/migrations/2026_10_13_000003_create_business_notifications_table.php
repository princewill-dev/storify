<?php

use App\Models\ConnectWebhookEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a business was told, and whether the email about it went.
 *
 * Two jobs in one row, and they are the same job seen from two sides: it is the
 * in-app record the business reads, and it is the uniqueness key that stops an
 * alert being sent twice. Before this table a business learned it had been paid by
 * looking at its bank statement — there was no payout mail at all, and the one
 * payment mail path that existed never fired on the automated settlement path.
 *
 * ## Why the row is the guard, and not a flag on the payout
 *
 * Every event this carries is raised from a place that already claims its work by
 * condition — `settle()`, `fail()`, `undo()` and the `settle_at` claim in
 * `recordCollectedSale()` — so the hook only ever runs on the one call that
 * changed something. That is not enough on its own: a webhook replay *after* a
 * `reconcile()` has already resolved the row hits the claim, and the claim is what
 * says so. What makes the alert exactly-once across both is the insert.
 *
 * `insertOrIgnore` on a unique `dedupe_key`, the same idiom as
 * {@see ConnectWebhookEvent::claim()}: the insert either happens or
 * tells you somebody else already did it, in one statement, with no read-then-write
 * to race. The key names the *event* rather than the row — `payout.paid:po:{ref}`,
 * `custody.credit:txn:{id}`, `payout.failed:po:{ref}:{attempt}` — which is what
 * makes a retried failure a second notification and a replayed success a
 * non-event, without either of them needing to know about the other.
 *
 * ## Why the mail state is on the row
 *
 * The mail is sent synchronously, in a try/catch, and this deployment has no
 * guaranteed queue worker — a money alert that might never drain is worse than a
 * slow one. So the failure mode to design for is not "the queue lost it" but "the
 * SMTP server refused it while the money moved anyway". `mail_error` is the record
 * of that: the notification exists, the business can see it in the app, and the
 * reason the email is missing is on the row instead of only in a log.
 *
 * Nothing here is ever deleted when a mail fails. The two channels are deliberately
 * independent — a notification with no email is a degraded alert, and dropping it
 * would turn a mail outage into a notification outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // The recipient as resolved at the moment of writing, so a later
            // ownership change does not reattribute an alert to somebody who was
            // not there. Null when no one could be named, which is a record worth
            // keeping rather than an insertion to refuse.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // `wallet.credited` | `payout.paid` | `payout.failed` |
            // `payout.reversed` | `payout.blocked`. A string, like every other
            // event-ish column here: the set is what the product decides it is,
            // and an enum in the schema needs a migration to add an alert.
            $table->string('type', 48);

            // `info` | `success` | `warning` | `critical`. For the in-app list, so
            // "your transfer was recalled" does not read like "your payment
            // arrived" at a glance.
            $table->string('level', 16)->default('info');

            $table->string('title', 191);

            // The message in full, written when the event happened. Stored rather
            // than rendered on read, because the figures that make it true —
            // balances, amounts, references — are facts about that moment and
            // re-deriving them later would describe the present instead.
            $table->text('body');

            // What the notification is about: a transaction or a payout. Nullable
            // and unconstrained, because an alert outlives neither safely — and a
            // notification whose subject was purged should still be readable.
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The figure the notification is about, where there is one. Kept out of
            // the prose so a list can total and compare without parsing a sentence.
            $table->bigInteger('amount_kobo')->nullable();
            $table->string('currency', 8)->nullable();

            $table->json('metadata')->nullable();

            // The exactly-once guard. Unique across the whole table, not scoped to
            // the business: the keys embed the ids that make them, so scoping would
            // only be a second way to say the same thing.
            $table->string('dedupe_key', 191)->unique('business_notifications_dedupe_unique');

            $table->timestamp('read_at')->nullable();

            // When the mail went, and why it did not. Both nullable and both
            // meaningful: `emailed_at` null with `mail_error` null means there was
            // no one to mail.
            $table->timestamp('emailed_at')->nullable();
            $table->string('mail_error', 500)->nullable();

            $table->timestamps();

            // The list a business opens: newest first, and "what have I not read".
            $table->index(['business_id', 'created_at'], 'business_notifications_business_created_index');
            $table->index(['business_id', 'read_at'], 'business_notifications_business_read_index');

            // The "which payout was this about" lookup from a payout screen.
            $table->index(['subject_type', 'subject_id'], 'business_notifications_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notifications');
    }
};
