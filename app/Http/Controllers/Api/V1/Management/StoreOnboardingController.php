<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\Stores\CreateStore;
use App\Enums\StoreType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\CreateStoreRequest;
use App\Http\Requests\Management\StoreOnboardingIndexRequest;
use App\Http\Resources\Management\StoreOnboardingFinalizeResource;
use App\Http\Resources\Management\StoreOnboardingOptionsResource;
use App\Http\Resources\Management\StoreOnboardingResource;
use App\Models\Store;
use App\Repositories\Management\StoreOnboardingRepository;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WS-02 — store onboarding: the parity store list, the create form's options,
 * store creation and the post-create finalize hand-off.
 *
 * The list lives at stores/onboarding/list rather than GET stores because
 * routes/api/v1/management.php registers stores/{store} before feature modules
 * are loaded, so any two-segment GET under stores/ is captured by the bind of
 * that route. A third segment keeps this unambiguous.
 *
 * Layering: the HTTP shape (status codes, the envelope, message strings, the
 * 403/422 refusals) stays here; the payload rules live in the Management
 * FormRequests, the list scope, filters, slug walk and customer aggregates in
 * StoreOnboardingRepository, the response shapes in the StoreOnboarding*
 * resources, and the create workflow — with its transaction boundary — in the
 * existing App\Actions\Stores\CreateStore action this controller has always
 * called. No service wraps that action: the multi-table write and its
 * transaction already live there.
 *
 * Provenance kept with the code it explains:
 * - the verified-owner 403 and the bank/staff "invalid selection" 422s stay
 *   in the controller body, at the same point in the sequence — a foreign
 *   pick is refused as 422 by deliberate anti-id-probing, never as 403;
 * - the slug unique rule moved into CreateStoreRequest, so a caller who is
 *   both unverified and malformed now answers 422 where it answered 403 —
 *   the known, accepted consequence of the extraction across this codebase.
 *   A valid payload from an unverified caller still answers 403, so nothing
 *   is escalated, and this is deliberately not worked around;
 * - the store-create options carry their user-derived defaults in the
 *   resource, while the staff/bank/currency reads stay business-scoped in
 *   the repository.
 */
class StoreOnboardingController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StoreOnboardingRepository $repository,
    ) {}

    public function index(StoreOnboardingIndexRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $filters = $request->validated();

        $stores = $this->repository->listQuery($user, $filters)
            ->paginate($filters['per_page'] ?? 12)
            ->withQueryString();

        // The legacy payload read a `customers_count` attribute that no store
        // ever had, so the count always rendered as null; count the distinct
        // buyers per store in one query for the whole page instead.
        $customerCounts = $this->repository->customerCountsForStores(
            $stores->getCollection()->pluck('id'),
        );

        return $this->ok(
            ['stores' => $stores->getCollection()
                ->map(fn (Store $store) => (new StoreOnboardingResource($store, (int) $customerCounts->get($store->id, 0)))
                    ->resolve($request))
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

        return $this->ok(
            (new StoreOnboardingOptionsResource($user, $this->repository->formOptions($user)))->resolve($request),
        );
    }

    public function store(CreateStoreRequest $request, CreateStore $createStore): JsonResponse
    {
        $user = $this->user($request);

        // The legacy create screen refused unverified owners before the form
        // was even usable; keep that gate on the write itself.
        if (! $user->is_verified) {
            return $this->error('Please verify your email before creating a store.', 403);
        }

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
            $data['slug'] = $this->repository->availableSlug($data['name']);
        }

        // Legacy handed these ids straight to the action, where an out-of-
        // business pick bubbled out as an exception; refuse them as 422s.
        // The queries are business-scoped in the repository; the refusals
        // stay here, 422 by design rather than 403 — deliberate
        // anti-id-probing.
        if (! empty($data['bank_id']) && ! $this->repository->businessBankExists($user, $data['bank_id'])) {
            return $this->error('Invalid bank account selection.', 422, [
                'bank_id' => ['The selected bank account is not available to this business.'],
            ]);
        }

        if (! empty($data['staff_ids'])) {
            $staffIds = array_map('intval', (array) $data['staff_ids']);

            $validCount = $this->repository->validStaffCount($user, $staffIds);

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

        $store = $this->repository->loadCounts($store);

        return $this->ok([
            'store' => (new StoreOnboardingResource($store, 0))->resolve($request),
        ], 'Store created successfully!', 201);
    }

    /**
     * The store create success page — logo, live URL and the next step the
     * owner's subscription state implies.
     */
    public function finalize(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $store = $this->repository->loadCounts($store);

        $subscriptionActive = (bool) $this->user($request)->business?->hasActiveSubscription();

        return $this->ok(
            (new StoreOnboardingFinalizeResource(
                $store,
                $this->repository->customerCount($store),
                $subscriptionActive,
            ))->resolve($request),
        );
    }
}
