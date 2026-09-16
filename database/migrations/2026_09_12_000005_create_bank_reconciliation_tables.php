<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('store_bank_id')->nullable()->constrained('store_banks')->nullOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->date('statement_date')->nullable();
            $table->unsignedBigInteger('opening_balance_kobo')->nullable();
            $table->unsignedBigInteger('closing_balance_kobo')->nullable();
            $table->string('status', 20)->default('imported'); // imported, reconciled
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('description')->nullable();
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_kobo'); // signed: positive credit, negative debit
            $table->foreignId('matched_journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('status', 20)->default('unmatched'); // unmatched, matched, ignored
            $table->timestamps();

            $table->index(['bank_statement_import_id', 'status']);
            $table->index(['business_id', 'transaction_date']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('store_bank_id')->nullable()->constrained('store_banks')->nullOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('bank_statement_import_id')->nullable()->constrained('bank_statement_imports')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('statement_closing_balance_kobo')->default(0);
            $table->unsignedBigInteger('cleared_balance_kobo')->default(0);
            $table->bigInteger('difference_kobo')->default(0);
            $table->string('status', 20)->default('in_progress'); // in_progress, completed
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};
