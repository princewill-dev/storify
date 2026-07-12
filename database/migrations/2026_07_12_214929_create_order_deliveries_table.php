<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('status')->default('pending')->comment('pending, assigned, picked_up, in_transit, out_for_delivery, delivered, failed, returned');
            $table->foreignId('delivery_route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('current_location')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('actual_delivery_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_signature')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};
