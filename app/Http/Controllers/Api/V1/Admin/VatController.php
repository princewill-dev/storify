<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListVatsRequest;
use App\Http\Requests\Admin\VatRequest;
use App\Http\Resources\Admin\VatResource;
use App\Models\Vat;
use App\Repositories\Admin\VatRepository;
use App\Services\Admin\VatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-12 — platform VAT rates.
 *
 * The active rate is what POS, cart and storefront pricing read
 * (`Vat::active()->orderByDesc('effective_at')->orderByDesc('id')`), so every
 * mutation here changes the tax charged on new sales.
 *
 * Business rule reproduced from legacy: VAT is never fully disabled. The
 * "Disable VAT" action writes a new 0% rate that supersedes the current one
 * instead of switching tax off.
 *
 * Legacy defect fixed: `VatController@toggle` created the 0% record but left
 * the previous rate active, so several rows claimed to be active at once. The
 * single-active invariant is now enforced on every path — create, update,
 * toggle and delete.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListVatsRequest` for the list filter and `VatRequest`, the shared
 * create/edit contract — the legacy controller validated both actions with
 * the same rule set), the list query in VatRepository, the write workflows and
 * their transaction boundaries in VatService, and row shaping in
 * `VatResource`; the platform-admin guard and the refusal messages
 * deliberately stay here so their order is unchanged. The delete is a single
 * model write with no transaction or shared query, so it stays on the
 * controller — wrapping it would be indirection with no benefit.
 */
class VatController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly VatRepository $vats,
        private readonly VatService $service,
    ) {}

    public function index(ListVatsRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $vats = $this->vats->paginateForAdmin($request->validated());

        return $this->ok(
            ['vats' => $vats->getCollection()->map(fn (Vat $vat) => $this->payload($vat))->values()->all()],
            null,
            200,
            $this->paginationMeta($vats),
        );
    }

    public function store(VatRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $vat = $this->service->create($request->validated());

        Log::info('api.admin.vat_created', [
            'actor_user_id' => $request->user()?->id,
            'vat_id' => $vat->id,
            'percentage' => (float) $vat->percentage,
        ]);

        return $this->ok(['vat' => $this->payload($vat)], 'VAT created.', 201);
    }

    public function update(VatRequest $request, Vat $vat): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // No `active` flag means "leave the current state alone"; marking a row
        // active supersedes whatever was active before. `has`/`boolean` are
        // read here, not in the rules, because sending `false` is not the same
        // as sending nothing.
        $activate = $request->has('active') ? $request->boolean('active') : $vat->active;

        if (! $activate && $vat->active) {
            // VAT can never be switched off directly — the only supported way
            // to stop charging is the 0% superseding record, so unsetting the
            // active flag would leave the platform with no rate at all.
            return $this->error('VAT cannot be switched off directly. Use the Disable VAT action to create a 0% rate, or activate another rate first.');
        }

        $this->service->update($vat, $request->validated(), $activate);

        Log::info('api.admin.vat_updated', [
            'actor_user_id' => $request->user()?->id,
            'vat_id' => $vat->id,
        ]);

        return $this->ok(['vat' => $this->payload($vat->fresh())], 'VAT updated.');
    }

    public function destroy(Request $request, Vat $vat): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($vat->active) {
            // Deleting the active rate would leave POS pricing with no rate at
            // all — the guard keeps the "VAT is never fully disabled" rule.
            return $this->error('The active VAT rate cannot be deleted. Activate a replacement rate first, or use Disable VAT to create a 0% rate.');
        }

        $vat->delete();

        Log::info('api.admin.vat_deleted', [
            'actor_user_id' => $request->user()?->id,
            'vat_id' => $vat->id,
        ]);

        return $this->ok([], 'VAT deleted.');
    }

    public function toggle(Request $request, Vat $vat): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // "Disable VAT" writes a 0% record that supersedes every other rate.
        // The legacy toggle only created the row and left the previous rate
        // active too; both writes now share one transaction so the
        // single-active invariant holds on this path as well.
        $zero = $this->service->createZeroRate();

        Log::info('api.admin.vat_zero_created', [
            'actor_user_id' => $request->user()?->id,
            'old_vat_id' => $vat->id,
            'new_vat_id' => $zero->id,
        ]);

        return $this->ok(['vat' => $this->payload($zero)], '0% VAT created.');
    }

    /**
     * The row payload, shaped by VatResource. Kept as a thin private seam so
     * the response sites read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function payload(Vat $vat): array
    {
        return VatResource::make($vat)->resolve();
    }
}
