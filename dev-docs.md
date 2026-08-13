{{ $company->logo }}
{{ $company->name }}
{{ $company->email }}
{{ $company->phone }}
{{ $company->address }}
{{ $company->branch_address }}


Next: Sitemap
I can add:

Route: GET /sitemap.xml
Controller: returns XML of site URLs (home, store pages, category pages, product pages).
Caching for performance.
Confirm:

Should I include dynamic products, categories, and stores in the sitemap? If so, I’ll query them and paginate the sitemap if it grows large (sitemap index + multiple sitemaps).

php artisan tinker --execute="(new Database\Seeders\ProductAndStockSeeder)->run(warehouseId: 'WH_CODE_HERE')"


php artisan permissions:sync


php artisan tinker --execute="
\$wh = App\Models\Warehouse::where('warehouse_code', 'WH_CODE')->first();
if (\$wh) {
    App\Models\StockLocation::where('locationable_type', App\Models\Warehouse::class)->where('locationable_id', \$wh->id)->delete();
    App\Models\Product::where('section_id', fn(\$q) => \$q->whereIn('id', \$wh->sections()->pluck('id')))->delete();
    \$wh->sections()->delete();
    \$wh->delete();
    echo 'Wiped';
}
"


Wipe a store:
php artisan tinker --execute="
\$st = App\Models\Store::where('store_id', 'st_XXXXXXXXXX')->first();
if (\$st) {
    App\Models\Transaction::whereHas('order', fn(\$q) => \$q->where('store_id', \$st->id))->delete();
    App\Models\Order::where('store_id', \$st->id)->delete();
    App\Models\StockLocation::where('locationable_type', App\Models\Store::class)->where('locationable_id', \$st->id)->delete();
    App\Models\Product::where('store_id', \$st->id)->delete();
    App\Models\PosSession::where('store_id', \$st->id)->delete();
    \$st->delete();
    echo 'Wiped';
}
"