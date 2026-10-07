<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\BusinessReasonRequest;
use App\Http\Resources\Admin\BusinessPayloadResource;
use App\Models\Business;
use App\Repositories\Admin\BusinessDirectoryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The admin business directory: list, detail, and the suspend/activate
 * actions with their audit log lines.
 *
 * The layer split: the directory query and its eager loads live in
 * `BusinessDirectoryRepository`, the reason validation in the shared
 * `BusinessReasonRequest`, response shaping in `BusinessPayloadResource`.
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope, pagination meta — plus the single-model status write and its
 * Log::info line. No service was extracted: there is no transaction, no
 * second table and no notification, just `update()` plus a log entry.
 *
 * The four `businesses` URIs are currently re-registered on
 * `BusinessLifecycleController` by routes/api/v1/admin/ad04-business-lifecycle.php,
 * which supersedes this controller with the fuller lifecycle contract; this
 * file's own (leaner) response contract is preserved unchanged while the
 * orchestrator decides which of the two is retired.
 */
class BusinessController extends ApiController
{
    public function __construct(
        private readonly BusinessDirectoryRepository $businesses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // This endpoint validates nothing today, so the filters keep the
        // exact leniency the query had inline: blank/missing keys are
        // skipped, sort falls back to created_at, direction to desc,
        // per_page to 20. Marshalling the values through the same
        // Request::filled()/string()/integer() reads keeps that semantics
        // identical — a FormRequest here would newly reject input the
        // endpoint currently tolerates.
        $businesses = $this->businesses->paginateForDirectory([
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'q' => $request->filled('q') ? $request->string('q')->toString() : null,
            'sort' => $request->string('sort')->toString(),
            'direction' => $request->string('direction')->toString(),
            'per_page' => (int) $request->integer('per_page', 20),
        ]);

        return $this->ok(
            $businesses->getCollection()->map(fn (Business $business) => $this->payload($business))->values()->all(),
            null,
            200,
            $this->paginationMeta($businesses)
        );
    }

    public function show(Business $business): JsonResponse
    {
        $business->load([
            'owner:id,name,email,account_code,phone,status',
            'stores:id,business_id,name,slug,status,store_type',
            'activeSubscription.subscriptionPlan:id,name',
        ])->loadCount(['stores', 'warehouses', 'users']);

        return $this->ok(['business' => $this->payload($business, detailed: true)]);
    }

    public function suspend(BusinessReasonRequest $request, Business $business): JsonResponse
    {
        $data = $request->validated();
        $business->update(['status' => 'suspended']);

        Log::info('api.admin.business_suspended', ['business_id' => $business->id, 'reason' => $data['reason']]);

        return $this->ok(['business' => $this->payload($business->fresh())], 'Business suspended.');
    }

    public function activate(BusinessReasonRequest $request, Business $business): JsonResponse
    {
        $data = $request->validated();
        $business->update(['status' => 'active']);

        Log::info('api.admin.business_activated', ['business_id' => $business->id, 'reason' => $data['reason']]);

        return $this->ok(['business' => $this->payload($business->fresh())], 'Business activated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Business $business, bool $detailed = false): array
    {
        return BusinessPayloadResource::make($business)->detailed($detailed)->resolve();
    }
}
