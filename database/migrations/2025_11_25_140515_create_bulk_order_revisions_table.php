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
        Schema::create('bulk_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_order_id')->constrained()->onDelete('cascade');
            $table->integer('revision_number');
            
            // Who made this revision
            $table->enum('created_by_type', ['admin', 'customer']);
            $table->unsignedBigInteger('created_by_id');
            
            // Revision details
            $table->text('notes')->nullable();
            $table->json('items_snapshot'); // Snapshot of all items at this revision
            $table->decimal('total_amount', 10, 2);
            
            // Customer acceptance (only set if created_by_type is 'customer')
            $table->boolean('is_customer_accepted')->default(false);
            
            $table->timestamps();
            
            // Index for faster queries
            $table->index(['bulk_order_id', 'revision_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_order_revisions');
    }
};
