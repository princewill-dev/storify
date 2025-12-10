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
        Schema::create('family_pack_orders', function (Blueprint $table) {
            $table->id();
            $table->string('pack_code')->unique(); // e.g., PACK-XXX
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('delivery_address_id')->nullable()->constrained('delivery_addresses')->onDelete('set null');
            $table->foreignId('delivery_route_id')->nullable()->constrained('delivery_routes')->onDelete('set null');
            
            // Pack configuration
            $table->enum('pack_type', ['single', 'recurring'])->default('single');
            $table->enum('payment_interval', ['6_months', '12_months'])->nullable();
            $table->foreignId('delivery_interval_id')->nullable()->constrained('delivery_intervals')->onDelete('set null');
            $table->integer('total_cycles')->nullable(); // 6 or 12 for recurring
            
            // Pricing
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('estimated_total', 10, 2)->default(0);
            
            // Status and tracking
            $table->enum('status', ['pending_review', 'approved', 'active', 'paused', 'cancelled', 'completed'])->default('pending_review');
            
            // Notes
            $table->text('notes')->nullable(); // Customer notes
            $table->text('review_notes')->nullable(); // Admin notes
            
            // Admin review
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            
            // Conversion
            $table->foreignId('first_order_id')->nullable()->constrained('orders')->onDelete('set null');
            
            // Tracking
            $table->enum('last_updated_by', ['customer', 'admin'])->default('customer');
            
            $table->timestamps();
            
            // Indexes
            $table->index('pack_code');
            $table->index('customer_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_pack_orders');
    }
};
