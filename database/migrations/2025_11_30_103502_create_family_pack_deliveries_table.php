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
        Schema::create('family_pack_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_pack_order_id')->constrained('family_pack_orders')->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('set null');
            
            // Delivery tracking
            $table->integer('cycle_number'); // 1, 2, 3, etc.
            $table->date('scheduled_date');
            $table->enum('status', ['pending', 'payment_pending', 'paid', 'processing', 'delivered', 'skipped', 'cancelled'])->default('pending');
            
            // Payment tracking
            $table->foreignId('payment_id')->nullable()->constrained('transactions')->onDelete('set null');
            $table->timestamp('payment_reminder_sent_at')->nullable(); // -1 day reminder
            $table->timestamp('payment_due_reminder_sent_at')->nullable(); // 0 day reminder
            $table->timestamp('payment_overdue_reminder_sent_at')->nullable(); // +1 day reminder
            
            // Skip tracking
            $table->foreignId('skipped_by')->nullable()->constrained('customers')->onDelete('set null');
            $table->timestamp('skipped_at')->nullable();
            
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('family_pack_order_id');
            $table->index('scheduled_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_pack_deliveries');
    }
};
