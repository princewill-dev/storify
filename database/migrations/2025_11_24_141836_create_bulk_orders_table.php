<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bulk_orders', function (Blueprint $table) {
            $table->id();
            $table->string('bulk_code')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->enum('status', ['pending_review', 'approved', 'rejected', 'payment_pending', 'completed', 'cancelled'])->default('pending_review');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('estimated_total', 10, 2);
            $table->text('notes')->nullable();
            $table->json('custom_items')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_orders');
    }
};
