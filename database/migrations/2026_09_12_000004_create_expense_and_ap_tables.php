<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('opening_balance_kobo')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->date('expense_date');
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('tax_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo');
            $table->string('currency', 3)->default('NGN');
            $table->foreignId('payment_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status', 20)->default('draft'); // draft, approved, paid, void
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'expense_date']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('bill_number', 40);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('subtotal_kobo')->default(0);
            $table->unsignedBigInteger('tax_kobo')->default(0);
            $table->unsignedBigInteger('total_kobo')->default(0);
            $table->unsignedBigInteger('amount_paid_kobo')->default(0);
            $table->string('status', 20)->default('open'); // draft, open, partial, paid, void
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'bill_number']);
        });

        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_cost_kobo')->default(0);
            $table->unsignedBigInteger('amount_kobo')->default(0);
            $table->timestamps();
        });

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->date('payment_date');
            $table->unsignedBigInteger('amount_kobo');
            $table->foreignId('payment_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('method', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('expense_categories');
    }
};
