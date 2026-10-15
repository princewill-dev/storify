<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells a saved address apart from the copy an order was delivered to.
 *
 * Until now `delivery_addresses` had exactly one writer — checkout — and it
 * wrote a fresh row per order, so every row was a snapshot by construction and
 * nothing had to say so. The customer address book puts a second kind of row in
 * the same table, and the two must not be confused: a saved address is the
 * customer's to edit and delete, while a snapshot is what a fulfilment record
 * points at and must never change under it.
 *
 * A flag rather than a `delivery_addresses.order_id`, which would have been a
 * second pointer in the opposite direction to `orders.delivery_address_id` and
 * a circular foreign key: neither row could be inserted before the other.
 *
 * Existing rows are classified by whether an order points at them. Rows no
 * order references — the ones left behind when an order was deleted — become
 * saved addresses, because the alternative is hiding an address the customer
 * typed from the only screen that could show it, and a saved address is
 * harmless next to a lost one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->boolean('is_snapshot')->default(false)->after('is_default');
        });

        // The book reads `(customer_id, is_snapshot)` on every load; the
        // snapshot side is written far more often than it is listed.
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->index(['customer_id', 'is_snapshot'], 'delivery_addresses_customer_snapshot_index');
        });

        // Chunked in PHP rather than a joined UPDATE, so the statement is the
        // same on every driver the suite runs against.
        $snapshotIds = [];
        DB::table('orders')
            ->whereNotNull('delivery_address_id')
            ->select('id', 'delivery_address_id')
            ->chunkById(1000, function ($orders) use (&$snapshotIds) {
                $snapshotIds = array_merge($snapshotIds, $orders->pluck('delivery_address_id')->all());
            }, 'id');

        foreach (array_chunk(array_unique($snapshotIds), 1000) as $chunk) {
            DB::table('delivery_addresses')->whereIn('id', $chunk)->update(['is_snapshot' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->dropIndex('delivery_addresses_customer_snapshot_index');
            $table->dropColumn('is_snapshot');
        });
    }
};
