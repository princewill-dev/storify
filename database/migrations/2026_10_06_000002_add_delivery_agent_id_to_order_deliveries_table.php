<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| WS-12 — persist the dispatch delivery agent
|--------------------------------------------------------------------------
| The legacy dispatch modal posted `delivery_agent_id` and the controller
| ignored it (it was only used to prefill the driver fields in Alpine), so
| there was no column to write it to. WS-12 persists the selected agent, so
| this adds the missing reference. Nullable — a free-typed driver is still
| allowed, matching the legacy modal.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_deliveries', 'delivery_agent_id')) {
            return;
        }

        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->foreignId('delivery_agent_id')
                ->nullable()
                ->after('driver_phone')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order_deliveries', 'delivery_agent_id')) {
            return;
        }

        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_agent_id');
        });
    }
};
