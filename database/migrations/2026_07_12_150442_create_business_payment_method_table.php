<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'payment_method_id']);
        });

        // Migrate existing BusinessGateway records
        $gateways = DB::table('business_gateways')->where('gateway', 'paystack')->get();
        $paystackId = DB::table('payment_methods')->where('code', 'paystack')->value('id');
        foreach ($gateways as $gw) {
            DB::table('business_payment_method')->insertOrIgnore([
                'business_id' => $gw->business_id,
                'payment_method_id' => $paystackId,
                'is_active' => $gw->is_active,
                'config' => json_encode(['public_key' => $gw->public_key, 'secret_key' => $gw->secret_key]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_method');
    }
};
