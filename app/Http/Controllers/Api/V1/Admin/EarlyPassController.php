<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\EarlyPass;
use App\Models\EarlyPassUsage;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
 *    vs `EARLYBIRD`);
 *  - the model auto-deactivates a pass when usage reaches `max_uses`, so an
 *    operator can flip an exhausted pass back active and it still cannot be
 *    redeemed — the payload exposes `is_exhausted`/`is_available` and the
 *    toggle response says so explicitly instead of implying it works;
 *  - delete is refused while any usage exists (the usages FK cascades, so the
 *    guard is the only protection for who-redeemed-what history). The payload
 *    carries `can_delete` so the SPA disables the action up front.
 */
class EarlyPassController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $this->listFilters($request);
        $term = trim((string) ($filters['q'] ?? ''));

        $passes = EarlyPass::query()
            ->withCount('usages')
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term) {
                $inner->where('code', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            }))
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            $passes->getCollection()->map(fn (EarlyPass $pass) => $this->payload($pass))->values()->all(),
            null,
            200,
            $this->paginationMeta($passes) + ['status_counts' => $this->statusCounts()],
        );
    }

    public function show(Request $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $usages = $earlyPass->usages()
            ->with([
                'user:id,account_code,name,email,business_id',
                'user.business:id,name,business_code',
                'store:id,store_id,name',
            ])
            ->orderByDesc('used_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok([
            'pass' => $this->payload($earlyPass->loadCount('usages')),
            'usages' => $usages->getCollection()->map(fn (EarlyPassUsage $usage) => [
                'id' => $usage->id,
                'used_at' => $usage->used_at?->toISOString(),
                'user' => $usage->user ? [
                    'id' => $usage->user->id,
                    'account_code' => $usage->user->account_code,
                    'name' => $usage->user->name,
                    'email' => $usage->user->email,
                ] : null,
                'business' => $usage->user?->business ? [
                    'id' => $usage->user->business->id,
                    'name' => $usage->user->business->name,
                    'business_code' => $usage->user->business->business_code,
                ] : null,
                'store' => $usage->store ? [
                    'id' => $usage->store->id,
                    'store_id' => $usage->store->store_id,
                    'name' => $usage->store->name,
                ] : null,
            ])->values()->all(),
        ], null, 200, $this->paginationMeta($usages));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Uppercase before validation so the unique check sees the stored
        // shape ("Codes are handled in uppercase").
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:50', Rule::unique('early_passes', 'code')],
            'description' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $pass = DB::transaction(function () use ($request, $data) {
            $pass = EarlyPass::create([
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'max_uses' => $data['max_uses'] ?? null,
                'is_active' => true,
            ]);

            ActivityRecorder::record(
                action: 'early_pass_created',
                description: "Early access pass '{$pass->code}' created",
                subject: $pass,
                new: $this->auditValues($pass),
                actor: $request->user(),
            );

            return $pass;
        });

        return $this->ok(['pass' => $this->payload($pass->loadCount('usages'))], 'Early access pass created.', 201);
    }

    public function update(Request $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The code is immutable after creation. Reject a changed value loudly
        // (echoing the same code back is fine for idempotent form posts)
        // instead of silently ignoring it the way the legacy PUT did.
        $submittedCode = $request->input('code');
        if ($submittedCode !== null && strtoupper(trim((string) $submittedCode)) !== $earlyPass->code) {
            throw ValidationException::withMessages([
                'code' => 'The code cannot be changed after creation.',
            ]);
        }

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $old = $this->auditValues($earlyPass);
        $maxUses = $data['max_uses'] ?? null;
        $usageCount = $earlyPass->usages()->count();

        // Lowering the cap to (or below) current usage makes the pass
        // unredeemable, and the redemption action auto-deactivates at the
        // limit — mirror that so the row is not active-but-dead. Raising the
        // cap never auto-reactivates; the operator toggles deliberately.
        $exhausted = $maxUses !== null && $usageCount >= $maxUses;

        DB::transaction(function () use ($request, $earlyPass, $data, $old, $maxUses, $exhausted) {
            $earlyPass->update([
                'description' => $data['description'] ?? null,
                'max_uses' => $maxUses,
                'is_active' => $exhausted ? false : $earlyPass->is_active,
            ]);

            ActivityRecorder::record(
                action: 'early_pass_updated',
                description: "Early access pass '{$earlyPass->code}' updated",
                subject: $earlyPass,
                old: $old,
                new: $this->auditValues($earlyPass->fresh()),
                actor: $request->user(),
            );
        });

        return $this->ok(['pass' => $this->payload($earlyPass->fresh()->loadCount('usages'))], 'Pass updated.');
    }

    public function toggleStatus(Request $request, EarlyPass $earlyPass): JsonResponse
    {
        $this->authorizePlatformAdmin();

        DB::transaction(function () use ($request, $earlyPass) {
            $earlyPass->update(['is_active' => ! $earlyPass->is_active]);

            ActivityRecorder::record(
                action: 'early_pass_status_toggled',
                description: "Early access pass '{$earlyPass->code}' ".($earlyPass->is_active ? 'activated' : 'deactivated'),
                subject: $earlyPass,
                old: ['is_active' => ! $earlyPass->is_active],
                new: ['is_active' => (bool) $earlyPass->is_active],
                actor: $request->user(),
            );
        });

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

        if ($earlyPass->usages()->exists()) {
            return $this->error('Cannot delete a used pass. Deactivate it instead.', 422);
        }

        $values = $this->auditValues($earlyPass);
        $code = $earlyPass->code;

        DB::transaction(function () use ($request, $earlyPass, $values, $code) {
            $earlyPass->delete();

            ActivityRecorder::record(
                action: 'early_pass_deleted',
                description: "Early access pass '{$code}' deleted",
                old: $values,
                actor: $request->user(),
            );
        });

        return $this->ok([], 'Pass deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = EarlyPass::query()
            ->selectRaw('is_active, COUNT(*) as aggregate')
            ->groupBy('is_active')
            ->pluck('aggregate', 'is_active')
            ->map(fn ($count) => (int) $count);

        $active = $counts[1] ?? 0;
        $inactive = $counts[0] ?? 0;

        return [
            'active' => $active,
            'inactive' => $inactive,
            'all' => $active + $inactive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EarlyPass $pass): array
    {
        $usageCount = (int) ($pass->usages_count ?? $pass->usages()->count());
        $maxUses = $pass->max_uses !== null ? (int) $pass->max_uses : null;
        $exhausted = $maxUses !== null && $usageCount >= $maxUses;

        return [
            'id' => $pass->id,
            'code' => $pass->code,
            'description' => $pass->description,
            'is_active' => (bool) $pass->is_active,
            // Same rule as the model's isAvailable(), computed from the count
            // we already loaded instead of firing a query per row.
            'is_available' => (bool) $pass->is_active && ! $exhausted,
            'is_exhausted' => $exhausted,
            'max_uses' => $maxUses,
            'usage_count' => $usageCount,
            'remaining_uses' => $maxUses !== null ? max(0, $maxUses - $usageCount) : null,
            'usage_label' => $usageCount.' / '.($maxUses ?? 'unlimited'),
            'can_delete' => $usageCount === 0,
            'created_at' => $pass->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(EarlyPass $pass): array
    {
        return [
            'code' => $pass->code,
            'description' => $pass->description,
            'max_uses' => $pass->max_uses !== null ? (int) $pass->max_uses : null,
            'is_active' => (bool) $pass->is_active,
        ];
    }
}
