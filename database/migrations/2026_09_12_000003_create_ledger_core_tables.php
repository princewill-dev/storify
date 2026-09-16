<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20); // asset, liability, equity, income, expense
            $table->string('subtype', 40)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('currency', 3)->default('NGN');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'type']);
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open'); // open, closed
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->string('name', 20); // e.g. 2026-09
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open'); // open, closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('ledger_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('key', 50);
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('entry_number', 30);
            $table->date('entry_date');
            $table->text('memo')->nullable();
            $table->string('reference', 100)->nullable();
            $table->nullableMorphs('source');
            $table->string('status', 20)->default('posted'); // draft, posted, void
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'entry_number']);
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('debit_kobo')->default(0);
            $table->unsignedBigInteger('credit_kobo')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('contact_type', 30)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('tax_kobo')->default(0);
            $table->timestamps();

            $table->index(['ledger_account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_mappings');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('ledger_accounts');
    }
};
