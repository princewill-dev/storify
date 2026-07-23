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
