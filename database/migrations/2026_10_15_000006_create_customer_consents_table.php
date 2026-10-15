<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only trail of what a customer agreed to, and when.
 *
 * A boolean column on `customers` would answer "are they opted in" and nothing
 * else — not when, not through which route, not what they were told at the
 * time. Those are the questions a consent record exists to answer, so each
 * change writes a row and the current state is the latest row per type.
 *
 * Records are never updated or deleted. Withdrawing consent appends
 * `granted = false`; rewriting history is the one thing this table must not
 * permit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // 'marketing', 'terms', ... — a string rather than an enum so a new
            // kind of consent is a code change and not a schema change.
            $table->string('type', 50);

            $table->boolean('granted');
            $table->string('source', 50)->nullable(); // checkout, account, admin
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_consents');
    }
};
