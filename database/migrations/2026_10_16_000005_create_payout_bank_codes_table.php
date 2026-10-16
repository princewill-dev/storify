<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One business bank account, as each payout provider sees it.
 *
 * ## The bug this exists for
 *
 * A business names its bank once, out of the bank picker, and the picker is
 * Paystack's list — so `store_banks.bank_code` holds a **Paystack** code. Payouts
 * leave through Bachs. That code was handed to Bachs verbatim, with no
 * translation anywhere in the chain, and the two providers number the institutions
 * a Nigerian shop actually banks with differently:
 *
 *     Opay          Paystack 999992   Bachs 305
 *     PalmPay       Paystack 999991   Bachs 100033
 *     Moniepoint    Paystack 50515    Bachs 090405
 *     Zenith        Paystack 057      Bachs 057
 *
 * Legacy commercial banks agree by luck and the fintechs do not. Bachs answers a
 * code it does not recognise with `400 BANK_NOT_PAYABLE`, which the sweep files as
 * `destination_unusable` — so after three attempts the business's account is
 * exhausted, the admin alert blames the provider, and re-adding the account
 * reproduces it exactly. Nothing anywhere named the real cause.
 *
 * ## Why a row per provider rather than a second column
 *
 * The two codes are not the same fact written twice; each is meaningless outside
 * the list it came from, and which one a payout needs is decided by whichever
 * provider is the default *at the moment of the send* — a thing that changes
 * without the business doing anything. `PayoutSweepService::context()` reads that
 * at dispatch, so the row is looked up per send rather than chosen once at setup.
 *
 * A pair of columns (`bachs_bank_code`, `paystack_bank_code`) would have to be
 * widened by a migration every time a provider is added, and would leave the
 * destination id — which is also per provider, and is what the send actually
 * passes — with nowhere to live but a second pair.
 *
 * ## The destination id is the point of the registration
 *
 * `ensureDestination()` creates a destination rather than finding one. Bachs'
 * documentation says otherwise and it is measurably false there: three identical
 * registrations returned three different ids. Because nothing stored the id,
 * every payout row registered a fresh destination, so destinations accumulated
 * without bound at the provider. Storing it here is what makes registration
 * happen **once**, when the business names the account, and the send read a row
 * instead of writing to the provider.
 *
 * ## What the nullable columns mean
 *
 * `bank_code` is nullable and non-null `failure_reason` is set instead when this
 * provider cannot pay to the bank at all — roughly 157 of Paystack's 290 banks do
 * not exist in Bachs' 286, so this is an ordinary outcome and not an error state.
 * Recording it rather than leaving the row absent is what lets the setup step say
 * *which* provider cannot pay this bank, instead of rediscovering it on every
 * payout attempt.
 *
 * `destination_id` is nullable separately, because a code can be resolved and the
 * registration still refuse — an account the provider will not accept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_bank_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_bank_id')
                ->constrained('store_banks')
                ->cascadeOnDelete();

            // The registry code — `bachs`, `paystack`. A string rather than an
            // enum, because `PayoutRailRegistry` is where the set of providers
            // lives and a second copy of it in the schema would drift.
            $table->string('provider', 32);

            // In this provider's space, never in another's.
            $table->string('bank_code', 20)->nullable();

            $table->string('destination_id')->nullable();
            $table->boolean('is_usable')->nullable();

            // Why this provider cannot be used for this bank, when it cannot.
            // Free text rather than a code, because it is written from the
            // provider's own refusal and read by a person.
            $table->text('failure_reason')->nullable();

            // When the provider was last asked. Distinct from `updated_at`,
            // which moves when anything on the row does — this is the one that
            // says how stale the answer is.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // One row per account per provider, and the constraint the resolver
            // upserts against. Not a convenience: without it a second resolve
            // would leave two rows holding two different destination ids and no
            // way to say which the send should use.
            $table->unique(['store_bank_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_bank_codes');
    }
};
