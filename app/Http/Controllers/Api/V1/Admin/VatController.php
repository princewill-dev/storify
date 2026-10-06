<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Vat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 */
class VatController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $vats = Vat::query()
            ->orderByDesc('active')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            ['vats' => $vats->getCollection()->map(fn (Vat $vat) => $this->payload($vat))->values()->all()],
            null,
            200,
            $this->paginationMeta($vats),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $vat = DB::transaction(function () use ($data) {
            // A new record always becomes the active one (legacy forced it
            // regardless of the modal's checkbox) and effective_at defaults to
            // now, so at most one row is ever active.
            Vat::query()->update(['active' => false]);

            return Vat::create([
                'percentage' => $data['percentage'],
                'active' => true,
                'effective_at' => $data['effective_at'] ?? now(),
            ]);
        });

        Log::info('api.admin.vat_created', [
            'actor_user_id' => $request->user()?->id,
            'vat_id' => $vat->id,
            'percentage' => (float) $vat->percentage,
        ]);

        return $this->ok(['vat' => $this->payload($vat)], 'VAT created.', 201);
    }

    public function update(Request $request, Vat $vat): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // No `active` flag means "leave the current state alone"; marking a row
        // active supersedes whatever was active before.
        $activate = $request->has('active') ? $request->boolean('active') : $vat->active;

        if (! $activate && $vat->active) {
            // VAT can never be switched off directly — the only supported way
            // to stop charging is the 0% superseding record, so unsetting the
            // active flag would leave the platform with no rate at all.
            return $this->error('VAT cannot be switched off directly. Use the Disable VAT action to create a 0% rate, or activate another rate first.');
        }

        DB::transaction(function () use ($vat, $data, $activate) {
            if ($activate) {
                Vat::query()->whereKeyNot($vat->getKey())->update(['active' => false]);
            }

            $vat->update([
                'percentage' => $data['percentage'],
                'effective_at' => array_key_exists('effective_at', $data) ? $data['effective_at'] : $vat->effective_at,
                'active' => $activate,
            ]);
        });

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
        // The legacy toggle only created the row; the previous rate stayed
        // active too. Both writes now share one transaction so the
        // single-active invariant holds on this path as well.
        $zero = DB::transaction(function () {
            $zero = Vat::create([
                'percentage' => 0,
                'active' => true,
                'effective_at' => now(),
            ]);

            Vat::query()->whereKeyNot($zero->getKey())->update(['active' => false]);

            return $zero;
        });

        Log::info('api.admin.vat_zero_created', [
            'actor_user_id' => $request->user()?->id,
            'old_vat_id' => $vat->id,
            'new_vat_id' => $zero->id,
        ]);

        return $this->ok(['vat' => $this->payload($zero)], '0% VAT created.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Vat $vat): array
    {
        return [
            'id' => $vat->id,
            'percentage' => (float) $vat->percentage,
            'active' => (bool) $vat->active,
            'effective_at' => $vat->effective_at?->toISOString(),
            'created_at' => $vat->created_at?->toISOString(),
        ];
    }
}
