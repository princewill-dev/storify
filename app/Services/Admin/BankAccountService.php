<?php

namespace App\Services\Admin;

use App\Models\BankAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WS-12 — platform receiving-bank-account lifecycle.
 *
 * A bank-account row and its public-disk logo are two halves of one record
 * (the row is what storefront/manual-transfer instructions render; the logo is
 * served by URL), so create, update and delete each keep the row write and the
 * file operation inside one transaction — the legacy controller opened the
 * same transaction around the same pair, and the lockstep survives here.
 *
 * `deleteLogo` deliberately swallows storage failures: the legacy controller
 * let a missing/unwritable file abort the whole request, including the row
 * mutation. The warning log keeps the failure visible without failing the
 * write.
 *
 * Refusals and HTTP shape stay in the controller: the platform-admin guard,
 * the status codes and the message strings. This layer owns the workflow and
 * the transaction boundary only; the audit `Log::info` calls remain on the
 * controller where they read the request's actor.
 */
final class BankAccountService
{
    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(array $data, ?UploadedFile $logo, bool $isActive): BankAccount
    {
        return DB::transaction(function () use ($data, $logo, $isActive) {
            if ($logo !== null) {
                $data['logo'] = $logo->store('bank-logos', 'public');
            }

            return BankAccount::create([
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'] ?? null,
                'logo' => $data['logo'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $isActive,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload
     * @param  bool|null  $isActive  null keeps the current flag (the payload
     *                               omitted `is_active`)
     */
    public function update(BankAccount $account, array $data, ?UploadedFile $logo, ?bool $isActive): void
    {
        DB::transaction(function () use ($account, $data, $logo, $isActive) {
            if ($logo !== null) {
                // A replacement logo supersedes the old file on the public disk.
                $this->deleteLogo($account->logo);
                $data['logo'] = $logo->store('bank-logos', 'public');
            }

            $account->update([
                'bank_name' => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name' => array_key_exists('account_name', $data) ? $data['account_name'] : $account->account_name,
                'logo' => $data['logo'] ?? $account->logo,
                'sort_order' => $data['sort_order'] ?? $account->sort_order,
                'is_active' => $isActive ?? $account->is_active,
            ]);
        });
    }

    public function delete(BankAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $this->deleteLogo($account->logo);
            $account->delete();
        });
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
}
