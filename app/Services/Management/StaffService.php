<?php

namespace App\Services\Management;

use App\Mail\StaffInvitationMail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The staff invite and edit workflows.
 *
 * invite() is StaffController::store()'s write sequence moved verbatim: the
 * user row, the Spatie role assignment, the tenancy-scoped store and warehouse
 * syncs, then the queued invitation mail. update() carries the edit's write
 * sequence: the scalar row update, the pin, and the same two intersection
 * syncs — the reason the two workflows share this class, since the
 * "intersect the selection with what the caller can reach" rule must not drift
 * between them.
 *
 * No transaction was added: neither workflow had one, and introducing a
 * boundary here would change what survives a mid-sequence failure. The
 * controller keeps the HTTP contract (status codes, messages, envelope), and
 * it resolves the request's presence semantics (`filled('pin')`,
 * `has('store_ids')`, `has('warehouse_ids')`) into the nullable parameters
 * below, because "absent" and "present but empty" mean different things:
 * a null list leaves the assignments untouched, an empty array clears them.
 *
 * A failed invite mail must not lose the staff record — it is caught and
 * logged exactly as before, and the invitation can be resent.
 */
final class StaffService
{
    /**
     * @param  array<string, mixed>  $data  validated by StoreStaffRequest
     * @param  UploadedFile|null  $photo  the request file, stored before the row is created as before
     */
    public function invite(User $user, array $data, ?UploadedFile $photo): User
    {
        $staff = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => 'staff',
            'business_id' => $user->business_id,
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
            'status' => 'invited',
            'is_verified' => true,
            'email_verified_at' => now(),
            'pos_pin' => $data['pin'] ?? null,
            'photo_path' => $photo?->store('photos', 'public'),
            'password' => bcrypt(Str::random(32)),
        ]);

        setPermissionsTeamId($staff->business_id);
        $staff->assignRole($data['role']);

        // Read unconditionally, before the empty-check, exactly where the
        // controller read it — the sync filter must not drift from it.
        $allowedStoreIds = $user->accessibleStoreIds();

        if (! empty($data['store_ids'])) {
            $staff->assignedStores()->sync(collect($data['store_ids'])->intersect($allowedStoreIds)->all());
        }

        if (! empty($data['warehouse_ids'])) {
            $warehouseIds = $user->accessibleWarehouseIds();
            $staff->assignedWarehouses()->sync(collect($data['warehouse_ids'])->intersect($warehouseIds)->all());
        }

        try {
            Mail::to($staff->email)->queue(new StaffInvitationMail($staff));
        } catch (\Throwable $e) {
            Log::error('api.staff.invite_mail_failed', ['user_id' => $staff->id, 'error' => $e->getMessage()]);
        }

        return $staff;
    }

    /**
     * @param  array<string, mixed>  $data  validated by UpdateStaffRequest
     * @param  string|null  $pin  null = not submitted (or submitted empty), leave the current pin
     * @param  array<int, mixed>|null  $storeIds  null = not submitted, leave the assignments
     * @param  array<int, mixed>|null  $warehouseIds  null = not submitted, leave the assignments
     */
    public function update(
        User $user,
        User $staff,
        array $data,
        ?string $pin,
        ?array $storeIds,
        ?array $warehouseIds,
    ): void {
        $staff->update(collect($data)->only(['name', 'phone', 'status'])->all());

        if ($pin !== null) {
            $staff->update(['pos_pin' => $pin]);
        }

        if ($storeIds !== null) {
            $staff->assignedStores()->sync(collect($storeIds)->intersect($user->accessibleStoreIds())->all());
        }

        if ($warehouseIds !== null) {
            $staff->assignedWarehouses()->sync(collect($warehouseIds)->intersect($user->accessibleWarehouseIds())->all());
        }
    }
}
