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
        Schema::create('family_pack_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_pack_order_id')->constrained('family_pack_orders')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            
            // Payment details
            $table->enum('payment_interval', ['6_months', '12_months']);
            $table->decimal('interval_amount', 10, 2); // Amount per payment interval
            $table->date('next_payment_date')->nullable();
            $table->date('last_payment_date')->nullable();
            $table->decimal('total_paid', 10, 2)->default(0);
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_paused')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->date('paused_until')->nullable(); // Null = indefinite pause
            
            // Cancellation
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            
            // Indexes
            $table->index('family_pack_order_id');
            $table->index('customer_id');
            $table->index('next_payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_pack_subscriptions');
    }
};
