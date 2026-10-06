<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\Stores\CreateStore;
use App\Enums\StoreType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\CreateStoreRequest;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-02 — store onboarding: the parity store list, the create form's options,
 * store creation and the post-create finalize hand-off.
 *
 * The list lives at stores/onboarding/list rather than GET stores because
 * routes/api/v1/management.php registers stores/{store} before feature modules
 * are loaded, so any two-segment GET under stores/ is captured by the bind of
 * that route. A third segment keeps this unambiguous.
 */
class StoreOnboardingController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'inactive', 'suspended', 'deleted'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $stores = $this->user($request)->accessibleStores()
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
                // Deleted stores never leak back into the default list.
                fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';

                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('store_id', 'like', $like));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->withCount(['products', 'categories', 'orders'])
            ->latest()
            ->paginate($filters['per_page'] ?? 12)
            ->withQueryString();

        // The legacy payload read a `customers_count` attribute that no store
        // ever had, so the count always rendered as null; count the distinct
        // buyers per store in one query for the whole page instead.
        $customerCounts = Order::query()
            ->whereIn('store_id', $stores->getCollection()->pluck('id'))
            ->whereNotNull('customer_id')
            ->selectRaw('store_id, count(distinct customer_id) as total')
            ->groupBy('store_id')
            ->pluck('total', 'store_id');

        return $this->ok(
            ['stores' => $stores->getCollection()
                ->map(fn (Store $store) => $this->payload($store, (int) $customerCounts->get($store->id, 0)))
                ->values()
                ->all()],
            null,
            200,
            $this->paginationMeta($stores),
        );
    }

    /**
     * Everything the create screen needs to render in one round trip —
     * legacy assembled the same data in the controller and passed it to Blade.
     */
    public function createOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $staff = User::query()
            ->where('business_id', $user->business_id)
            ->where('role', 'staff')
            ->where('status', 'active')
            ->with('roles')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $banks = StoreBank::query()
            ->where('business_id', $user->business_id)
            ->orderBy('bank_name')
            ->get();

        return $this->ok([
            'defaults' => [
                'name' => $user->name,
                'support_email' => $user->email,
                'support_phone' => $user->phone,
                'address' => $user->location,
            ],
            'currencies' => Currency::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'symbol'])
                ->map(fn (Currency $currency) => [
                    'id' => $currency->id,
                    'name' => $currency->name,
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                ])->all(),
            'banks' => $banks->map(fn (StoreBank $bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'masked_account_number' => $bank->masked_account_number,
                'is_primary' => (bool) $bank->is_primary,
                'is_verified' => (bool) $bank->is_verified,
            ])->all(),
            'staff' => $staff->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'roles' => $member->roles->pluck('name')->all(),
            ])->all(),
            'main_domain' => config('app.main_domain', parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]);
    }

    public function store(CreateStoreRequest $request, CreateStore $createStore): JsonResponse
    {
        $user = $this->user($request);

        // The legacy create screen refused unverified owners before the form
        // was even usable; keep that gate on the write itself.
        if (! $user->is_verified) {
            return $this->error('Please verify your email before creating a store.', 403);
        }

        // The FormRequest already validated the payload; these extra rules
        // close two gaps in it — a taken slug must fail validation rather than
        // surfacing the unique index as a 500, and the slug is the live URL.
        $request->validate([
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('stores', 'slug')],
        ]);

        $data = $request->validated();
        $data['support_email'] ??= $user->email;
        $data['support_phone'] ??= $user->phone;
        $data['address'] ??= $user->location;

        $hasWebsite = $request->boolean('has_website');
        $isPhysical = $request->boolean('is_physical');

        $data['has_website'] = $hasWebsite;
        // The legacy form never persisted the store model it asked about, so
        // every created store kept the schema default; derive it from the two
        // checkboxes instead.
        $data['store_type'] = match (true) {
            $isPhysical && $hasWebsite => StoreType::BOTH->value,
            $isPhysical => StoreType::PHYSICAL->value,
            default => StoreType::ONLINE->value,
        };
        $data['physical_address'] = $isPhysical ? ($data['physical_address'] ?? null) : null;

        if (empty($data['slug'])) {
            // Prefer the deterministic -1, -2 suffix the slug check suggests
            // over the model's random suffix.
            $data['slug'] = $this->availableSlug($data['name']);
        }

        // Legacy handed these ids straight to the action, where an out-of-
        // business pick bubbled out as an exception; refuse them as 422s.
        if (! empty($data['bank_id']) && ! StoreBank::query()
            ->where('business_id', $user->business_id)
            ->whereKey($data['bank_id'])
            ->exists()) {
            return $this->error('Invalid bank account selection.', 422, [
                'bank_id' => ['The selected bank account is not available to this business.'],
            ]);
        }

        if (! empty($data['staff_ids'])) {
            $staffIds = array_map('intval', (array) $data['staff_ids']);

            $validCount = User::query()
                ->where('business_id', $user->business_id)
                ->where('role', 'staff')
                ->whereIn('id', $staffIds)
                ->count();

            if ($validCount !== count(array_unique($staffIds))) {
                return $this->error('One or more selected staff members are invalid.', 422, [
                    'staff_ids' => ['Select staff from this business only.'],
                ]);
            }
        }

        try {
            $store = $createStore->execute($user, $data, $request->file('logo'));
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $errorReference = Str::upper(Str::random(8));

            Log::error('api.management.store_create_failed', [
                'error_ref' => $errorReference,
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'sql' => $e instanceof QueryException ? $e->getSql() : null,
            ]);

            return $this->error("We could not create your store. Please try again. (Ref: {$errorReference})", 500);
        }

        $store->loadCount(['products', 'categories', 'orders']);

        return $this->ok([
            'store' => $this->payload($store, 0),
        ], 'Store created successfully!', 201);
    }

    /**
     * The store create success page — logo, live URL and the next step the
     * owner's subscription state implies.
     */
    public function finalize(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $store->loadCount(['products', 'categories', 'orders']);

        $subscriptionActive = (bool) $this->user($request)->business?->hasActiveSubscription();

        return $this->ok([
            'store' => $this->payload($store, $this->storeCustomerCount($store)),
            'storefront_url' => $this->storefrontUrl($store),
            'subscription_active' => $subscriptionActive,
            'next_step' => $subscriptionActive ? 'dashboard' : 'settings',
        ]);
    }

    /**
     * The legacy slug check walked -1, -2… until it found a free name. Keep
     * that contract for slugs generated server-side at create time.
     */
    private function availableSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'store';
        }

        $reserved = config('storefront.reserved_subdomains', []);
        $slug = $base;
        $counter = 1;

        while (in_array($slug, $reserved, true) || Store::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function storeCustomerCount(Store $store): int
    {
        return (int) Order::query()
            ->where('store_id', $store->id)
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');
    }

    private function storefrontUrl(Store $store): ?string
    {
        if (! $store->has_website || ! $store->slug) {
            return null;
        }

        // Same rule the legacy links used: local dev serves storefronts from
        // the root domain, everything else from {slug}.{main_domain}.
        if (app()->environment('local')) {
            return url($store->slug);
        }

        $domain = config('app.main_domain', parse_url((string) config('app.url'), PHP_URL_HOST));

        return 'https://'.$store->slug.'.'.$domain;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Store $store, ?int $customersCount = null): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'balance' => (int) $store->balance,
            'payment_mode' => $store->payment_mode,
            'description' => $store->description,
            'location' => $store->physical_address ?: $store->address,
            'address' => $store->address,
            'physical_address' => $store->physical_address,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'instagram_url' => $store->instagram_url,
            'facebook_url' => $store->facebook_url,
            'twitter_url' => $store->twitter_url,
            'tiktok_url' => $store->tiktok_url,
            'currency_id' => $store->currency_id,
            'views' => (int) $store->views,
            'logo_url' => $store->logoUrl(),
            'storefront_url' => $this->storefrontUrl($store),
            'products_count' => $store->products_count ?? null,
            'categories_count' => $store->categories_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'customers_count' => $customersCount ?? ($store->customers_count ?? null),
            'created_at' => $store->created_at?->toISOString(),
        ];
    }
}
