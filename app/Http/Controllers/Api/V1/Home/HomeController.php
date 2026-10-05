<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\SupportMessageAdmin;
use App\Mail\SupportMessageReceipt;
use App\Models\CompanyService;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class HomeController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'company' => $this->company(),
            'stats' => [
                'stores' => Store::where('status', 'active')->count(),
                'products' => Product::where('status', 'active')->count(),
            ],
            'plans' => $this->plans(),
            'testimonials' => $this->testimonials(),
            'services' => $this->services(),
            'stores' => $this->featuredStores(),
        ]);
    }

    public function stores(): JsonResponse
    {
        return $this->ok(['stores' => $this->featuredStores(all: true)]);
    }

    public function support(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        try {
            $adminEmail = Setting::query()->first()?->support_email ?? config('mail.from.address');

            if ($adminEmail) {
                Mail::to($adminEmail)->send(new SupportMessageAdmin($validated));
            } else {
                Log::warning('Support email not configured. Message logged but not sent to admin.', $validated);
            }

            Mail::to($validated['email'])->send(new SupportMessageReceipt($validated));

            Log::info('Home support message sent', ['email' => $validated['email'], 'subject' => $validated['subject']]);

            return $this->ok([], 'Your message has been received. We will get back to you shortly.');
        } catch (\Exception $e) {
            Log::error('Failed to send home support message', [
                'error' => $e->getMessage(),
                'email' => $validated['email'],
            ]);

            return $this->error('An error occurred while sending your message. Please try again later.', 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function company(): array
    {
        return Cache::remember('home_api_company', 600, function () {
            try {
                $setting = Schema::hasTable('settings') ? Setting::query()->first() : null;
            } catch (\Throwable $e) {
                $setting = null;
            }

            return [
                'name' => $setting->company_name ?? config('app.name'),
                'description' => $setting->company_description ?? null,
                'logo_url' => $setting?->company_logo_path ? asset('storage/'.$setting->company_logo_path) : asset('logo.png'),
                'favicon_url' => $setting?->company_favicon_path ? asset('storage/'.$setting->company_favicon_path) : asset('favicon.png'),
                'email' => $setting->support_email ?? null,
                'phone' => $setting->support_phone ?? null,
                'address' => $setting->company_address ?? null,
                'seo' => [
                    'title' => $setting->og_title ?? config('app.name'),
                    'description' => $setting->og_description ?? null,
                    'image' => $setting?->og_image_path ? asset('storage/'.$setting->og_image_path) : null,
                    'url' => $setting->og_url ?? url('/'),
                    'type' => $setting->og_type ?? 'website',
                ],
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plans(): array
    {
        return SubscriptionPlan::active()
            ->where('is_trial', false)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'amount' => (float) $plan->amount,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'interval_count' => (int) $plan->interval_count,
                'features' => $plan->features ?? [],
                'is_default' => (bool) $plan->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function testimonials(): array
    {
        return Testimonial::where('status', 'active')
            ->orderBy('position')
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (Testimonial $testimonial) => [
                'id' => $testimonial->id,
                'name' => $testimonial->name,
                'occupation' => $testimonial->occupation,
                'message' => $testimonial->message,
                'photo_url' => $testimonial->photo ? asset('storage/'.$testimonial->photo) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function services(): array
    {
        return CompanyService::where('status', 'active')
            ->ordered()
            ->get()
            ->map(fn (CompanyService $service) => [
                'id' => $service->id,
                'title' => $service->title,
                'description' => $service->description,
                'page_link' => $service->page_link,
                'image_url' => $service->background_image_path ? asset('storage/'.$service->background_image_path) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function featuredStores(bool $all = false): array
    {
        $query = Store::where('status', 'active')
            ->where('has_website', true);

        if (! $all) {
            $query->latest()->take(6);
        }

        return $query->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            ])
            ->values()
            ->all();
    }
}
