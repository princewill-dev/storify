<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop4me_requests', function (Blueprint $table) {
            $table->id();
            $table->string('list_id')->unique();
            // Linking columns kept flexible for now; FKs can be added later
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('currency_id')->nullable()->index();

            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();

            // pending, accepted, rejected, filled, dispatched, delivered, closed
            $table->string('status')->default('pending')->index();

            // delivery linkage (address captured later in funnel)
            $table->unsignedBigInteger('delivery_address_id')->nullable()->index();
            // order verification preference at handoff
            $table->string('verification_mode')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop4me_requests');
    }
};
