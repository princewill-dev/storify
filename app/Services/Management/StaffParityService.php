<?php

namespace App\Services\Management;

use App\Mail\StaffInvitationSpaMail;
use App\Models\StaffDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WS-20 — the staff write workflows.
 *
 * Invite and edit pair the user row with its Spatie roles, the store and
 * warehouse assignments, the photo and the documents inside one
 * DB::transaction, and the invitation mail goes out after that transaction
 * has returned. The controller keeps the HTTP contract (status codes, refusal
 * messages, envelope) and the tenant guards; suspend, activate, removal and
 * document deletion are single-row writes and stay there.
 *
 * Ordering preserved from the controller: the resolved role names are
 * computed by the FormRequest before this service runs, so an empty role
 * selection fails without opening a transaction, and a failed mail must not
 * lose the staff record — the invitation can be resent from the directory.
 */
final class StaffParityService
{
    /**
     * The invite workflow: one transaction for the user row, its roles, its
     * assignments and its documents, then the invitation mail.
     *
     * @param  array<string, mixed>  $data  validated by StaffParityStoreRequest
     * @param  array<int, string>  $roleNames  resolved by StaffParityStoreRequest
     */
    public function invite(Request $request, User $actor, array $data, array $roleNames): User
    {
        $plainPassword = $data['password'] ?? null;

        $staff = DB::transaction(function () use ($request, $actor, $data, $roleNames, $plainPassword) {
            $staff = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'staff',
                'business_id' => $actor->business_id,
                'invitation_token' => Str::random(64),
                'invited_at' => now(),
                'status' => 'invited',
                'is_verified' => true,
                'email_verified_at' => now(),
                'pos_pin' => $data['pin'] ?? null,
                'photo_path' => $request->hasFile('photo')
                    ? $request->file('photo')->store('photos', 'public')
                    : null,
                // A pre-set password is stored as given; without one the row
                // gets a random secret the staff member never sees.
                'password' => $plainPassword ?? bcrypt(Str::random(32)),
                'force_password_change' => $plainPassword !== null,
            ]);

            setPermissionsTeamId($staff->business_id);
            $staff->assignRole($roleNames);

            $this->syncAssignments($request, $actor, $staff);
            $this->storeDocuments($request, $staff);

            return $staff;
        });

        $this->sendInvitation($staff, $plainPassword);

        return $staff;
    }

    /**
     * The edit workflow: one transaction for the field update, the pin, the
     * photo, the role reassignment, the assignments and the document changes.
     *
     * @param  array<string, mixed>  $data  validated by StaffParityUpdateRequest
     * @param  array<int, string>|null  $roleNames  null when the payload did not reassign roles
     */
    public function update(Request $request, User $actor, User $staff, array $data, ?array $roleNames): void
    {
        DB::transaction(function () use ($request, $actor, $staff, $data, $roleNames) {
            $staff->update(collect($data)->only(['name', 'phone', 'status'])->all());

            // An explicitly present-but-empty pin clears it, matching the
            // legacy form's "leave empty to clear" semantics.
            if ($request->has('pin')) {
                $staff->update(['pos_pin' => $data['pin'] ?? null]);
            }

            if ($request->hasFile('photo')) {
                if ($staff->photo_path) {
                    Storage::disk('public')->delete($staff->photo_path);
                }
                $staff->update(['photo_path' => $request->file('photo')->store('photos', 'public')]);
            } elseif ($request->boolean('remove_photo') && $staff->photo_path) {
                Storage::disk('public')->delete($staff->photo_path);
                $staff->update(['photo_path' => null]);
            }

            // Role reassignment (WS-20 repair): the old update ignored roles
            // entirely, so a cashier could never be promoted to manager.
            if ($roleNames !== null) {
                setPermissionsTeamId((int) $actor->business_id);
                $staff->syncRoles($roleNames);
            }

            $this->syncAssignments($request, $actor, $staff);
            $this->deleteDocuments($request, $staff);
            $this->storeDocuments($request, $staff);
        });
    }

    /**
     * Rotate the invitation and re-send it. One workflow so the token write
     * and the mail cannot drift apart; the caller owns the
     * status-is-invited 409.
     */
    public function resendInvitation(User $staff): void
    {
        $staff->update([
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
        ]);

        $this->sendInvitation($staff, null);
    }

    /**
     * Store and warehouse assignments, intersected with what the acting user
     * can reach: a foreign id is dropped rather than attached, the same rule
     * the controller applied inline.
     */
    private function syncAssignments(Request $request, User $actor, User $staff): void
    {
        if ($request->has('store_ids')) {
            $allowed = $actor->accessibleStoreIds();
            $staff->assignedStores()->sync(
                collect((array) $request->input('store_ids'))->intersect($allowed)->values()->all(),
            );
        }

        if ($request->has('warehouse_ids')) {
            $allowed = $actor->accessibleWarehouseIds();
            $staff->assignedWarehouses()->sync(
                collect((array) $request->input('warehouse_ids'))->intersect($allowed)->values()->all(),
            );
        }
    }

    private function storeDocuments(Request $request, User $staff): void
    {
        if (! $request->hasFile('documents')) {
            return;
        }

        $tags = $request->input('document_tags', []);

        foreach ($request->file('documents') as $index => $file) {
            $path = $file->store('staff-documents', 'public');

            $staff->documents()->create([
                'file_name' => $file->hashName(),
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'tag' => $tags[$index] ?? null,
            ]);
        }
    }

    private function deleteDocuments(Request $request, User $staff): void
    {
        $ids = (array) $request->input('delete_document_ids', []);

        if ($ids === []) {
            return;
        }

        $staff->documents()->whereIn('id', $ids)->get()->each(function (StaffDocument $document) {
            Storage::disk('public')->delete($document->file_path);
            $document->delete();
        });
    }

    /**
     * A failed mail must not lose the staff record — the invitation can be
     * resent from the directory.
     */
    private function sendInvitation(User $staff, ?string $plainPassword): void
    {
        try {
            Mail::to($staff->email)->queue(new StaffInvitationSpaMail($staff, $plainPassword));
        } catch (\Throwable $e) {
            Log::error('api.staff.invite_mail_failed', [
                'user_id' => $staff->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
