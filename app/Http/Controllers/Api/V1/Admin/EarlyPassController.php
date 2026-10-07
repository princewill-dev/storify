<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListEarlyPassesRequest;
use App\Http\Requests\Admin\StoreEarlyPassRequest;
use App\Http\Requests\Admin\UpdateEarlyPassRequest;
use App\Http\Resources\Admin\EarlyPassResource;
use App\Http\Resources\Admin\EarlyPassUsageResource;
use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Repositories\Admin\EarlyPassRepository;
use App\Services\Admin\EarlyPassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * WS-11 (admin console) — early-access pass issuance and tracking.
 *
 * Legacy `/office/early-access` (Businesses › Access Codes) was a 20-a-page
 * list with create/edit modals, an activate/deactivate toggle, a used-guard
 * delete and a detail page showing the usage history. Behavioural notes kept:
 *
 *  - codes are uppercased on the way in and immutable after creation; the
 *    uniqueness check runs *after* uppercasing (legacy validated the raw
 *    input first, which leaned on the column collation to catch `earlybird`
 *    vs `EARLYBIRD`) — the uppercasing and its uniqueness check live in
 *    `StoreEarlyPassRequest::prepareForValidation()`, the immutability
 *    refusal stays in `update()` beside the platform guard;
 *  - the model auto-deactivates a pass when usage reaches `max_uses`, so an
 *    operator can flip an exhausted pass back active and it still cannot be
 *    redeemed — the payload exposes `is_exhausted`/`is_available` and the
 *    toggle response says so explicitly instead of implying it works;
 *  - delete is refused while any usage exists (the usages FK cascades, so the
 *    guard is the only protection for who-redeemed-what history). The payload
 *    carries `can_delete` so the SPA disables the action up front.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListEarlyPassesRequest`, `StoreEarlyPassRequest`, `UpdateEarlyPassRequest`),
 * the directory/usage queries in `EarlyPassRepository`, the write workflows
 * with their transaction boundaries and audit rows in `EarlyPassService`, and
 * the row shaping in `EarlyPassResource`/`EarlyPassUsageResource`. The
 * platform-admin guard deliberately stays in the body — it must not move into
 * FormRequest::authorize() or middleware — and the code-immutability refusal
 * stays beside it so an unauthorised caller sending a changed code is still
 * refused 403 first.
 */
class EarlyPassController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly EarlyPassRepository $passes,
        private readonly EarlyPassService $service,
    ) {}

    public function index(ListEarlyPassesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validated();

        $passes = $this->passes->paginateDirectory($filters, (int) ($filters['per_page'] ?? 20));

        return $this->ok(
            $passes->getCollection()->map(fn (EarlyPass $pass) => $this->payload($pass))->values()->all(),
            null,
            200,
            $this->paginationMeta($passes) + ['status_counts' => $this->passes->statusCounts()],
        );
    }

    public function show(Request $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $usages = $this->passes->paginateUsages($earlyPass, (int) $request->integer('per_page', 20));

        return $this->ok([
            'pass' => $this->payload($earlyPass->loadCount('usages')),
            'usages' => $usages->getCollection()
                ->map(fn (EarlyPassUsage $usage) => EarlyPassUsageResource::make($usage)->resolve())
                ->values()->all(),
        ], null, 200, $this->paginationMeta($usages));
    }

    public function store(StoreEarlyPassRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $pass = $this->service->create($request->validated(), $request->user());

        return $this->ok(['pass' => $this->payload($pass->loadCount('usages'))], 'Early access pass created.', 201);
    }

    public function update(UpdateEarlyPassRequest $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The code is immutable after creation. Reject a changed value loudly
        // (echoing the same code back is fine for idempotent form posts)
        // instead of silently ignoring it the way the legacy PUT did. The
        // refusal stays in the controller, after the platform guard, so
        // guard order (403 before this 422) is unchanged.
        $submittedCode = $request->input('code');
        if ($submittedCode !== null && strtoupper(trim((string) $submittedCode)) !== $earlyPass->code) {
            throw ValidationException::withMessages([
                'code' => 'The code cannot be changed after creation.',
            ]);
        }

        $this->service->update($earlyPass, $request->validated(), $request->user());

        return $this->ok(['pass' => $this->payload($earlyPass->fresh()->loadCount('usages'))], 'Pass updated.');
    }

    public function toggleStatus(Request $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->service->toggleStatus($earlyPass, $request->user());

        $pass = $earlyPass->fresh()->loadCount('usages');
        $payload = $this->payload($pass);

        $message = match (true) {
            ! $pass->is_active => 'Pass has been deactivated.',
            ! $payload['is_available'] => 'Pass has been activated, but it is exhausted (usage limit reached) so it cannot be redeemed.',
            default => 'Pass has been activated.',
        };

        return $this->ok(['pass' => $payload], $message);
    }

    public function destroy(Request $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The usages FK cascades, so this guard is the only protection for the
        // who-redeemed-what history.
        if ($earlyPass->usages()->exists()) {
            return $this->error('Cannot delete a used pass. Deactivate it instead.', 422);
        }

        $this->service->delete($earlyPass, $request->user());

        return $this->ok([], 'Pass deleted successfully.');
    }

    /**
     * The row shape, kept as a thin seam the list and every write echo share;
     * the fields live in EarlyPassResource.
     *
     * @return array<string, mixed>
     */
    private function payload(EarlyPass $pass): array
    {
        return EarlyPassResource::make($pass)->resolve();
    }
}
