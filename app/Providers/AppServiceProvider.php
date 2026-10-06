<?php

namespace App\Providers;

use App\Models\Currency;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Disable all caching via CACHE_ENABLED=false in .env
        if (env('CACHE_ENABLED', true) === false) {
            config(['cache.default' => 'array']);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production (for Cloudflare proxy)
        if ($this->app->environment('production')) {
            \URL::forceScheme('https');
        }

        // Superadmin (plain role column) bypasses all Spatie permission checks.
        Gate::before(function ($user, $ability) {
            if ($user instanceof User && $user->role === User::ROLE_SUPERADMIN) {
                return true;
            }

            return null;
        });

        // API rate limits: generous global limit, strict on auth endpoints.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip().'|'.(string) $request->input('email'));
        });

        // Configure Log Viewer access - only allow superadmins (if package is installed)
        if (class_exists(LogViewer::class)) {
            LogViewer::auth(function ($request) {
                $user = $request->user('web');

                return $user !== null && ($user->role ?? null) === 'superadmin';
            });
        }

        Paginator::useBootstrapFive();

        // Wrap in try-catch to handle missing cache table during migrations
        try {
            $company = Cache::remember('company_settings', 600, function () {
                try {
                    if (! Schema::hasTable('settings')) {
                        $s = null;
                    } else {
                        $s = Setting::query()->first();
                    }
                } catch (\Throwable $e) {
                    $s = null;
                }
                $logoPath = $s?->company_logo_path;
                $logoUrl = $logoPath ? asset('storage/'.$logoPath) : asset('logo.png');
                $faviconPath = $s?->company_favicon_path;
                $faviconUrl = $faviconPath ? asset('storage/'.$faviconPath) : asset('favicon.png');
                $certPath = $s?->company_certificate_path;
                $certUrl = $certPath ? asset('storage/'.$certPath) : null;
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
                    'og_image' => ($s?->og_image_path ? asset('storage/'.$s->og_image_path) : null),
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
    }
}
