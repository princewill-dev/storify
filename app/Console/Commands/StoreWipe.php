<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StoreWipe extends Command
{
    protected $signature = 'store:wipe
                            {identifier : The store ID (e.g. st_6507410129)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Wipe all data belonging to a store';

    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        $store = Store::where('store_id', $identifier)->first();

        if (!$store) {
            $this->error("Store [{$identifier}] not found.");
            return Command::FAILURE;
        }

        $orderCount = Order::where('store_id', $store->id)->count();
        $productCount = Product::where('store_id', $store->id)->count();
        $invoiceCount = \App\Models\Invoice::where('store_id', $store->id)->count();
        $sessionCount = PosSession::where('store_id', $store->id)->count();

        $this->warn("Store: {$store->name} ({$store->store_id})");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Orders', $orderCount],
                ['Products', $productCount],
                ['Invoices', $invoiceCount],
                ['POS Sessions', $sessionCount],
            ]
        );

        if (!$this->option('force') && !$this->confirm('This will PERMANENTLY delete all this data. Continue?', false)) {
            $this->info('Aborted.');
            return Command::FAILURE;
        }

        DB::transaction(function () use ($store) {
            $orderIds = Order::where('store_id', $store->id)->pluck('id');
            $invoiceIds = \App\Models\Invoice::where('store_id', $store->id)->pluck('id');

            if ($orderIds->isNotEmpty() || $invoiceIds->isNotEmpty()) {
                Transaction::where(function ($q) use ($orderIds, $invoiceIds) {
                    if ($orderIds->isNotEmpty()) $q->orWhereIn('order_id', $orderIds);
                    if ($invoiceIds->isNotEmpty()) $q->orWhereIn('invoice_id', $invoiceIds);
                })->delete();
            }

            Order::where('store_id', $store->id)->delete();
            \App\Models\Invoice::where('store_id', $store->id)->delete();
            \App\Models\ServiceCharge::where('store_id', $store->id)->delete();

            StockLocation::where('locationable_type', Store::class)
                ->where('locationable_id', $store->id)
                ->delete();

            Product::where('store_id', $store->id)->delete();
            \App\Models\Category::where('store_id', $store->id)->delete();
            PosSession::where('store_id', $store->id)->delete();

            DB::table('store_payment_method')->where('store_id', $store->id)->delete();
            DB::table('store_bank')->where('store_id', $store->id)->delete();
            DB::table('staff_assignments')->where('assignmentable_type', Store::class)
                ->where('assignmentable_id', $store->id)->delete();
            \App\Models\DeliveryRoute::where('store_id', $store->id)->delete();

            $store->delete();
        });

        $this->info("Store [{$identifier}] wiped successfully.");
        return Command::SUCCESS;
    }
}
