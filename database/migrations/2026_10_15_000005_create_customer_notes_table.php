<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal notes a business keeps about a customer.
 *
 * Never shown to the customer; this is the staff-facing record ("called about
 * the missing item, refunded", "buys for a school, invoice required").
 *
 * `user_id` is the author, not the subject — the subject is `customer_id`. It
 * is nullable so a note outlives the staff account that wrote it, which it
 * should: the note is the business's, not the employee's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
