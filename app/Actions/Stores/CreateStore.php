<?php

namespace App\Actions\Stores;

use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CreateStore
{
    public function execute(User $owner, array $data, ?UploadedFile $logo = null): Store
    {
        if (! $owner->business_id) {
            throw new DomainException('Complete business setup before creating a store.');
        }

        $bankId = $data['bank_id'] ?? null;
        $staffIds = array_values(array_filter($data['staff_ids'] ?? []));
        unset($data['bank_id'], $data['staff_ids'], $data['business_id'], $data['logo']);

        $logoPath = $logo?->store('stores/logos', 'public');

        try {
            return DB::transaction(function () use ($owner, $data, $bankId, $staffIds, $logoPath): Store {
                $store = Store::create($data + [
                    'user_id' => $owner->id,
                    'business_id' => $owner->business_id,
                    'status' => Store::STATUS_PENDING,
                    'logo_path' => $logoPath,
                ]);

                if ($bankId) {
                    $bank = StoreBank::query()
                        ->where('business_id', $owner->business_id)
                        ->findOrFail($bankId);
                    $store->assignedBanks()->attach($bank->id, ['is_active' => true]);
                }

                if ($staffIds) {
                    $validStaffIds = User::query()
                        ->where('business_id', $owner->business_id)
                        ->where('role', 'staff')
                        ->whereIn('id', $staffIds)
                        ->pluck('id');

                    if ($validStaffIds->count() !== count(array_unique($staffIds))) {
                        throw new DomainException('One or more selected staff members are invalid.');
                    }

                    $store->assignedStaff()->sync($validStaffIds->all());
                }

                return $store;
            });
        } catch (\Throwable $e) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }

            throw $e;
        }
    }
}
