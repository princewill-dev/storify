<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WS-12 — platform receiving bank accounts.
 *
 * These rows are what storefront/manual-transfer instructions surface to
 * customers, so bank_name and account_number are required and the logo is a
 * public-disk upload replacing (and deleting) the previous file.
 *
 * The legacy resource route also exposed a `show` action whose Blade view
 * never existed (every hit 500'd); there is deliberately no show route here.
 */
class BankAccountController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $accounts = BankAccount::query()
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(function ($query) use ($term) {
                $query->where('bank_name', 'like', "%{$term}%")
                    ->orWhere('account_number', 'like', "%{$term}%")
                    ->orWhere('account_name', 'like', "%{$term}%");
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->ok(
            ['bank_accounts' => $accounts->getCollection()->map(fn (BankAccount $account) => $this->payload($account))->values()->all()],
            null,
            200,
            $this->paginationMeta($accounts),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate($this->rules());

        $account = DB::transaction(function () use ($request, $data) {
            if ($request->hasFile('logo')) {
                $data['logo'] = $request->file('logo')->store('bank-logos', 'public');
            }

            return BankAccount::create([
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'] ?? null,
                'logo' => $data['logo'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $request->boolean('is_active', true),
            ]);
        });

        Log::info('api.admin.bank_account_created', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $account->id,
            'bank_name' => $account->bank_name,
        ]);

        return $this->ok(['bank_account' => $this->payload($account)], 'Bank account created successfully.', 201);
    }

    public function update(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate($this->rules());

        DB::transaction(function () use ($request, $bankAccount, $data) {
            if ($request->hasFile('logo')) {
                // A replacement logo supersedes the old file on the public disk.
                $this->deleteLogo($bankAccount->logo);
                $data['logo'] = $request->file('logo')->store('bank-logos', 'public');
            }

            $bankAccount->update([
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name' => array_key_exists('account_name', $data) ? $data['account_name'] : $bankAccount->account_name,
                'logo' => $data['logo'] ?? $bankAccount->logo,
                'sort_order' => $data['sort_order'] ?? $bankAccount->sort_order,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $bankAccount->is_active,
            ]);
        });

        Log::info('api.admin.bank_account_updated', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return $this->ok(['bank_account' => $this->payload($bankAccount->fresh())], 'Bank account updated successfully.');
    }

    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizePlatformAdmin();

        DB::transaction(function () use ($bankAccount) {
            $this->deleteLogo($bankAccount->logo);
            $bankAccount->delete();
        });

        Log::info('api.admin.bank_account_deleted', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return $this->ok([], 'Bank account deleted successfully.');
    }

    public function toggleActive(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $bankAccount->update(['is_active' => ! $bankAccount->is_active]);

        Log::info('api.admin.bank_account_toggled', [
            'actor_user_id' => $request->user()?->id,
            'bank_account_id' => $bankAccount->id,
            'is_active' => $bankAccount->is_active,
        ]);

        return $this->ok(
            ['bank_account' => $this->payload($bankAccount->fresh())],
            'Bank account status updated successfully.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    private function deleteLogo(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            // A missing/unwritable file must not fail the row mutation; the
            // legacy controller let a storage exception abort the request.
            Log::warning('api.admin.bank_account_logo_delete_failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'account_name' => $account->account_name,
            'logo_url' => $account->logo ? asset('storage/'.$account->logo) : null,
            'sort_order' => (int) $account->sort_order,
            'is_active' => (bool) $account->is_active,
            'created_at' => $account->created_at?->toISOString(),
            'updated_at' => $account->updated_at?->toISOString(),
        ];
    }
}
