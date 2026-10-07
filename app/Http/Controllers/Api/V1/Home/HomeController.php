<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Home\SupportRequest;
use App\Http\Resources\Home\CompanyResource;
use App\Http\Resources\Home\CompanyServiceResource;
use App\Http\Resources\Home\PlanResource;
use App\Http\Resources\Home\StoreResource;
use App\Http\Resources\Home\TestimonialResource;
use App\Models\CompanyService;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Testimonial;
use App\Services\Home\SupportMessageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The public marketing site surface (`/api/v1/home`): the home page payload,
 * the full featured-store list and the contact form.
 *
 * Layering: the HTTP shape — statuses, message strings, the `{data}` envelope
 * and field order — stays here; the contact-form rules live in SupportRequest,
 * the mail workflow in SupportMessageService and the payload shaping in
 * App\Http\Resources\Home. No repository was extracted: every read is a single
 * scoped Eloquent call under the eight-line composition bar, each is used by
 * one endpoint (featuredStores() by two, and it is a status filter plus an
 * optional `latest()->take(6)`), and the marketing tables are platform-wide,
 * so none of them is a tenancy risk that centralising would protect.
 *
 * `company()` caches the *shaped array* — not the row — for ten minutes under
 * `home_api_company`, the key SettingsService and StoreLifecycleService bust
 * after every save; both the key and the TTL are a contract with those
 * services.
 *
 * The `support` failure path stays here: any exception from the mail workflow
 * (including the settings read inside it) is logged and mapped to the same
 * 500 the endpoint has always returned.
 */
class HomeController extends ApiController
{
    public function __construct(
        private readonly SupportMessageService $supportMessages,
    ) {}

    public function index(): JsonResponse
    {
        return $this->ok([
            'company' => $this->company(),
            'stats' => [
                'stores' => Store::where('status', 'active')->count(),
                'products' => Product::where('status', 'active')->count(),
            ],
            'plans' => PlanResource::collection($this->plans())->resolve(),
            'testimonials' => TestimonialResource::collection($this->testimonials())->resolve(),
            'services' => CompanyServiceResource::collection($this->services())->resolve(),
            'stores' => StoreResource::collection($this->featuredStores())->resolve(),
        ]);
    }

    public function stores(): JsonResponse
    {
        return $this->ok([
            'stores' => StoreResource::collection($this->featuredStores(all: true))->resolve(),
        ]);
    }

    public function support(SupportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $this->supportMessages->send($validated);

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
     * The company block: the singleton settings row is read behind a
     * table-existence guard (a fresh install can serve the page before
     * `settings` exists) and the whole shaped payload is cached for ten
     * minutes under the key the admin services bust.
     *
     * @return array<string, mixed>
     */
    private function company(): array
    {
        return Cache::remember('home_api_company', 600, function (): array {
            try {
                $setting = Schema::hasTable('settings') ? Setting::query()->first() : null;
            } catch (\Throwable $e) {
                $setting = null;
            }

            return CompanyResource::make($setting)->resolve();
        });
    }

    /**
     * Active, non-trial plans in display order.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    private function plans(): Collection
    {
        return SubscriptionPlan::active()
            ->where('is_trial', false)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Active testimonials, position first and newest as the tiebreak, capped
     * at six.
     *
     * @return Collection<int, Testimonial>
     */
    private function testimonials(): Collection
    {
        return Testimonial::where('status', 'active')
            ->orderBy('position')
            ->latest()
            ->take(6)
            ->get();
    }

    /**
     * Active company services in display order.
     *
     * @return Collection<int, CompanyService>
     */
    private function services(): Collection
    {
        return CompanyService::where('status', 'active')
            ->ordered()
            ->get();
    }

    /**
     * Active stores with a live storefront; the six newest for the home page,
     * every one of them for the dedicated stores endpoint.
     *
     * @return Collection<int, Store>
     */
    private function featuredStores(bool $all = false): Collection
    {
        $query = Store::where('status', 'active')
            ->where('has_website', true);

        if (! $all) {
            $query->latest()->take(6);
        }

        return $query->get();
    }
}
