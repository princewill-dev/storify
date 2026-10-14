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
use App\Models\Currency;
use App\Models\Store;
use App\Repositories\Management\StoreOnboardingRepository;
use App\Services\Payments\Custody\WalletSummaryService;
use App\Support\Plans\StoreQuota;
use App\Support\Stores\StoreModelDefaults;
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
 *   resource, while the staff read stays business-scoped in the repository;
 * - a stated "sells nowhere" — both flags false — is refused as a 422 here,
 *   beside the other two refusals, rather than left to the write. `store_type`
 *   is derived from those two flags, so neither of them being true is not a
 *   store shape at all; the create form requires one of the two, and this is
 *   the API saying the same thing to a caller that states it outright.
 *
 * The create form no longer offers a payout account, so its options payload
 * carries no bank list — but `bank_id` is still accepted and still validated
 * below. The column and the assignment it makes outlive the picker that used to
 * set it, and a client built before this change keeps creating stores exactly
 * as it did.
 */
class StoreOnboardingController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StoreOnboardingRepository $repository,
        private readonly WalletSummaryService $wallet,
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

        // One wallet walk for the page, not one per row — see the resource.
        $walletBlocks = $user->business !== null
            ? $this->wallet->storeBlocks($user->business)
            : [];

        return $this->ok(
            ['stores' => $stores->getCollection()
                ->map(fn (Store $store) => (new StoreOnboardingResource(
                    $store,
                    (int) $customerCounts->get($store->id, 0),
                    $walletBlocks[(int) $store->id] ?? null,
                ))->resolve($request))
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
        $options = $this->repository->formOptions($user);
        $options['quota'] = $user->business === null ? null : StoreQuota::payload($user->business);

        return $this->ok(
            (new StoreOnboardingOptionsResource($user, $options))->resolve($request),
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

        // The plan's store limit, checked before anything is read from the
        // request or written. It was never checked anywhere before: the platform
        // stored a `settings.store_creation_limit`, showed it in the admin
        // console, validated it, and read it from no creation path — so a
        // three-store plan could run thirty.
        //
        // A 403 in the gate's own shape rather than the 422 this controller uses
        // for bad input, because nothing about the request is wrong. The business
        // is asking for something it is not entitled to, which is the same
        // category as the subscription gate and is answered the same way.
        $refusal = $user->business === null ? null : StoreQuota::refusal($user->business);

        if ($refusal !== null) {
            return response()->json($refusal, 403);
        }

        $data = $request->validated();
        $data['support_email'] ??= $user->email;
        $data['support_phone'] ??= $user->phone;
        $data['address'] ??= $user->location;

        // What the business said it was here for, at signup, read back into the
        // store model. The create form no longer asks — see
        // StoreModelDefaults for why and for the mapping itself.
        $inferred = StoreModelDefaults::fromIntents($user->business?->onboarding_intents);

        // A caller that states a flag still wins; the inference only fills
        // silence. That is what keeps an SPA built before this change creating
        // stores exactly as it did, and what leaves room for an explicit
        // override later.
        $hasWebsite = $request->has('has_website') ? $request->boolean('has_website') : $inferred['has_website'];
        $isPhysical = $request->has('is_physical') ? $request->boolean('is_physical') : $inferred['is_physical'];

        // A caller that states both flags false is describing a store that
        // sells nowhere: no counter to sell from, no storefront to buy from.
        // The create form cannot produce that — it requires one of the two —
        // but the API can be told it outright, and the alternative is a store
        // that exists with nothing to sell through.
        //
        // Refused only when both are *stated*. Silence still falls through to
        // the inference above, so a client built before those checks existed
        // creates exactly the store it always did; this is the one shape that
        // cannot have been meant.
        if ($request->has('has_website') && $request->has('is_physical') && ! $hasWebsite && ! $isPhysical) {
            return $this->error('Choose whether this store sells in person, online, or both.', 422, [
                'is_physical' => ['A store must sell in person, online, or both.'],
            ]);
        }

        $data['has_website'] = $hasWebsite;

        // Not a column. It is the caller's answer to "does this store have a
        // counter", consumed here and turned into the store type and the
        // address rule below; `$data` becomes the store's attributes wholesale,
        // so it has to go before it reaches the write.
        unset($data['is_physical']);

        // Written only when there is something to write. `validated()` omits an
        // absent optional key, and defaulting it here would overrule the
        // column's own default for every caller that never sends it — which is
        // the pre-existing contract this still keeps: silence plus no answer at
        // signup leaves `pos_enabled` untouched.
        //
        // The till follows the counter, and the counter is now something a
        // caller can state rather than only something the signup answers imply.
        // Reading the inferred flag here meant an owner who ticked "Physical
        // store" got a counter and then nothing to put on it whenever their
        // signup answers disagreed — the one case the create form exists to
        // let them express. `$isPhysical` is the effective counter, stated or
        // inferred; a caller that states `pos_enabled` itself still wins.
        if ($request->has('pos_enabled')) {
            $data['pos_enabled'] = $request->boolean('pos_enabled');
        } elseif ($isPhysical) {
            $data['pos_enabled'] = true;
        }

        // The legacy form never persisted the store model it asked about, so
        // every created store kept the schema default; derive it from the two
        // checkboxes instead.
        $data['store_type'] = match (true) {
            $isPhysical && $hasWebsite => StoreType::BOTH->value,
            $isPhysical => StoreType::PHYSICAL->value,
            default => StoreType::ONLINE->value,
        };
        $data['physical_address'] = $isPhysical ? ($data['physical_address'] ?? null) : null;

        // Currency is the platform's, not the store's. Storify is the merchant
        // of record and holds one wallet currency, so a per-store picker was
        // asking the owner to choose something the platform had already
        // decided — and leaving it blank left `currency_id` null, which the
        // dashboards then rendered as a missing symbol. Absent now means the
        // platform default rather than nothing.
        $data['currency_id'] ??= Currency::query()->where('is_default', true)->value('id');

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
            'store' => (new StoreOnboardingResource($store, 0, $this->walletBlockFor($store)))->resolve($request),
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
                $this->walletBlockFor($store),
            ))->resolve($request),
        );
    }

    /**
     * This store's wallet line, for the one-store screens.
     *
     * The list computes the page's blocks once and passes them down; a screen
     * showing a single store asks for its own, and a store whose business row
     * cannot be loaded gets null — the payload's own fallback — rather than a
     * second walk.
     *
     * @return array<string, mixed>|null
     */
    private function walletBlockFor(Store $store): ?array
    {
        $business = $store->business;

        return $business !== null ? $this->wallet->storeWalletBlock($business, $store) : null;
    }
}
