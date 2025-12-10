<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;
use App\Models\Store;
use App\Models\CompanyService;
use App\Models\Feature;
use Illuminate\Support\Facades\Auth;
use App\Models\Currency;
use Illuminate\Support\Facades\Route;
use App\Models\Vendor;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('vendor', fn ($value) => Vendor::where('account_id', $value)->first() ?? abort(404));
        // Force HTTPS in production (for Cloudflare proxy)
        if ($this->app->environment('production')) {
            \URL::forceScheme('https');
        }

        Paginator::useBootstrapFive();
        
        // Wrap in try-catch to handle missing cache table during migrations
        try {
            $company = Cache::remember('company_settings', 600, function () {
                try {
                    if (!Schema::hasTable('settings')) {
                        $s = null;
                    } else {
                        $s = Setting::query()->first();
                    }
                } catch (\Throwable $e) {
                    $s = null;
                }
                $logoPath = $s?->company_logo_path;
                $logoUrl = $logoPath ? asset('storage/' . $logoPath) : asset('logo.png');
                $faviconPath = $s?->company_favicon_path;
                $faviconUrl = $faviconPath ? asset('storage/' . $faviconPath) : asset('favicon.png');
                $certPath = $s?->company_certificate_path;
                $certUrl = $certPath ? asset('storage/' . $certPath) : null;
                // Safely resolve default currency (may not exist during fresh installs/migrations)
                $curr = null;
                try {
                    if (Schema::hasTable('currencies')) {
                        $curr = Currency::where('is_default', true)->first();
                    }
                } catch (\Throwable $e) {
                    $curr = null;
                }
                return (object) [
                    'logo' => $logoUrl,
                    'logo_path' => $logoPath,
                    'favicon' => $faviconUrl,
                    'favicon_path' => $faviconPath,
                    'certificate' => $certUrl,
                    'certificate_path' => $certPath,
                    'company_name' => $s->company_name ?? null,
                    'company_description' => $s->company_description ?? null,
                    'name' => $s->company_name ?? null,
                    'email' => $s->support_email ?? null,
                    'phone' => $s->support_phone ?? null,
                    'address' => $s->company_address ?? null,
                    'branch_address' => $s->branch_address ?? null,
                    // Currency (default only)
                    'currency' => $curr?->name,
                    'currency_code' => $curr?->code,
                    'currency_symbol' => $curr?->symbol,
                    // SEO defaults
                    'og_title' => $s->og_title ?? config('app.name'),
                    'og_description' => $s->og_description ?? null,
                    'og_image' => ($s?->og_image_path ? asset('storage/' . $s->og_image_path) : null),
                    'og_url' => $s->og_url ?? url('/'),
                    'og_type' => $s->og_type ?? 'website',
                    // Greeting Modal
                    'greeting_modal_enabled' => $s->greeting_modal_enabled ?? false,
                    'greeting_modal_frequency' => $s->greeting_modal_frequency ?? 'never',
                ];
            });
        } catch (\Throwable $e) {
            // Cache table doesn't exist, use defaults
            $company = (object) [
                'logo' => asset('logo.png'),
                'logo_path' => null,
                'favicon' => asset('favicon.png'),
                'favicon_path' => null,
                'certificate' => null,
                'certificate_path' => null,
                'company_name' => config('app.name'),
                'company_description' => null,
                'name' => config('app.name'),
                'email' => null,
                'phone' => null,
                'address' => null,
                'branch_address' => null,
                'currency' => null,
                'currency_code' => null,
                'currency_symbol' => null,
                'og_title' => config('app.name'),
                'og_description' => null,
                'og_image' => null,
                'og_url' => url('/'),
                'og_type' => 'website',
                'greeting_modal_enabled' => false,
                'greeting_modal_frequency' => 'never',
            ];
        }
        View::share('company', $company);

        // Share company services with home views
        try {
            $services = Cache::remember('company_services', 600, function () {
                try {
                    if (!Schema::hasTable('company_services')) {
                        return collect();
                    }
                    return CompanyService::where('status', 'active')
                        ->ordered()
                        ->get();
                } catch (\Throwable $e) {
                    return collect();
                }
            });
        } catch (\Throwable $e) {
            $services = collect();
        }
        View::composer(['home.*'], function ($view) use ($services) {
            $view->with('services', $services);
        });

        // Share main store with admin views only (requires authenticated admin)
        View::composer(['admin.*'], function ($view) {
            if (!Auth::check()) {
                return;
            }
            $user = Auth::user();
            if (!in_array($user->role ?? null, ['superadmin','admin'], true)) {
                return;
            }

            try {
                $store = Cache::remember('admin_main_store', 300, function () {
                    try {
                        if (!Schema::hasTable('settings')) { return null; }
                        $s = Setting::query()->first();
                        $mainStoreId = $s->main_store_id ?? null;
                        $st = null;
                        if ($mainStoreId && Schema::hasTable('stores')) {
                            $st = Store::find($mainStoreId);
                        }
                        if (!$st && Schema::hasTable('stores')) {
                            $st = Store::where('status','active')->orderBy('id')->first();
                        }
                        return $st;
                    } catch (\Throwable $e) {
                        return null;
                    }
                });
            } catch (\Throwable $e) {
                $store = null;
            }

            if ($store) {
                $view->with('adminMainStore', $store);
            }
        });

        // Share active company services with page links to home views for header navigation
        View::composer(['home.*','home.components.*'], function ($view) {
            $services = Cache::remember('nav_company_services', 300, function () {
                try {
                    if (!Schema::hasTable('company_services')) { return collect(); }
                    return CompanyService::where('status','active')
                        ->whereNotNull('page_link')
                        ->ordered()
                        ->get(['title','page_link']);
                } catch (\Throwable $e) {
                    return collect();
                }
            });
            $view->with('navServices', $services);
            
            // Share main store for cart functionality
            $mainStore = Cache::remember('home_main_store', 300, function () {
                try {
                    if (!Schema::hasTable('settings')) { return null; }
                    $s = Setting::query()->first();
                    $mainStoreId = $s->main_store_id ?? null;
                    $st = null;
                    if ($mainStoreId && Schema::hasTable('stores')) {
                        $st = Store::find($mainStoreId);
                    }
                    if (!$st && Schema::hasTable('stores')) {
                        $st = Store::where('status','active')->orderBy('id')->first();
                    }
                    return $st;
                } catch (\Throwable $e) {
                    return null;
                }
            });
            $view->with('mainStore', $mainStore);
            
            // Share suggested products for search "You May Also Like" carousel
            $suggestedProducts = Cache::remember('search_suggested_products', 300, function () use ($mainStore) {
                try {
                    if (!$mainStore || !Schema::hasTable('products')) {
                        return collect();
                    }
                    
                    // Get featured products first
                    $featured = \App\Models\Product::with('images', 'store')
                        ->where('store_id', $mainStore->id)
                        ->where('status', 'active')
                        ->where('featured', true)
                        ->inRandomOrder()
                        ->limit(10)
                        ->get();
                    
                    // If less than 10 featured, get more random products
                    if ($featured->count() < 10) {
                        $remaining = 10 - $featured->count();
                        $others = \App\Models\Product::with('images', 'store')
                            ->where('store_id', $mainStore->id)
                            ->where('status', 'active')
                            ->where('featured', false)
                            ->inRandomOrder()
                            ->limit($remaining)
                            ->get();
                        
                        return $featured->merge($others);
                    }
                    
                    return $featured;
                } catch (\Throwable $e) {
                    return collect();
                }
            });
            $view->with('suggestedProducts', $suggestedProducts);
        });

        View::composer(['home.*','home.components.features_cta'], function ($view) {
            try {
                if (!Schema::hasTable('features')) {
                    $featureCtas = collect();
                } else {
                    $featureCtas = Feature::ordered()->get();
                }
            } catch (\Throwable $e) {
                $featureCtas = collect();
            }

            $view->with('featureCtas', $featureCtas);
        });

        View::composer('vendors.components.header', function ($view) {
            $data = $view->getData();
            $company = $data['company'] ?? (object) [];

            $vendor = Auth::guard('vendor')->user();
            if ($vendor && !$vendor->relationLoaded('store')) {
                $vendor->load('store');
            }

            $store = $vendor?->store;
            $brandLogo = $store?->logo_path
                ? asset('storage/' . $store->logo_path)
                : ($company->favicon ?? asset('vendor_files/assets/images/logo.png'));
            $brandName = $store?->name
                ?? $vendor?->name
                ?? ($company->name ?? config('app.name'));

            $view->with([
                'vendorBrandLogo' => $brandLogo,
                'vendorBrandName' => $brandName,
                'vendorBrandStore' => $store,
                'vendorBrandVendor' => $vendor,
            ]);
        });
    }
}
