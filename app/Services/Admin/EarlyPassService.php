<?php

namespace App\Services\Admin;

use App\Models\EarlyPass;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;

/**
 * WS-11 (admin console) — early-pass write workflows.
 *
 * Every mutation pairs its table write with the audit row inside one
 * transaction (the recorder contract: a rejected audit row must take the
 * mutation down with it), keeping the exact order the controller used:
 * create/update/toggle/delete, each followed by its `early_pass_*` record.
 * The controller keeps the HTTP shape — validation, the used-pass 422
 * refusal and the response echo.
 *
 * Provenance kept from the controller, moved here with the code it explains:
 * the model auto-deactivates a pass when usage reaches `max_uses`, so
 * lowering the cap to (or below) current usage mirrors that and the pass is
 * not left active-but-dead. Raising the cap never auto-reactivates — the
 * operator toggles deliberately.
 */
final class EarlyPassService
{
    /**
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(array $data, ?User $actor): EarlyPass
    {
        return DB::transaction(function () use ($data, $actor) {
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
                actor: $actor,
            );

            return $pass;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload
     */
    public function update(EarlyPass $pass, array $data, ?User $actor): void
    {
        $old = $this->auditValues($pass);
        $maxUses = $data['max_uses'] ?? null;
        $usageCount = $pass->usages()->count();

        // Lowering the cap to (or below) current usage makes the pass
        // unredeemable, and the redemption action auto-deactivates at the
        // limit — mirror that so the row is not active-but-dead. Raising the
        // cap never auto-reactivates; the operator toggles deliberately.
        $exhausted = $maxUses !== null && $usageCount >= $maxUses;

        DB::transaction(function () use ($pass, $data, $old, $maxUses, $exhausted, $actor) {
            $pass->update([
                'description' => $data['description'] ?? null,
                'max_uses' => $maxUses,
                'is_active' => $exhausted ? false : $pass->is_active,
            ]);

            ActivityRecorder::record(
                action: 'early_pass_updated',
                description: "Early access pass '{$pass->code}' updated",
                subject: $pass,
                old: $old,
                new: $this->auditValues($pass->fresh()),
                actor: $actor,
            );
        });
    }

    /**
     * Flip the active flag and audit it. The description/old/new values read
     * the flag *after* the update, exactly as the controller's closure did.
     */
    public function toggleStatus(EarlyPass $pass, ?User $actor): void
    {
        DB::transaction(function () use ($pass, $actor) {
            $pass->update(['is_active' => ! $pass->is_active]);

            ActivityRecorder::record(
                action: 'early_pass_status_toggled',
                description: "Early access pass '{$pass->code}' ".($pass->is_active ? 'activated' : 'deactivated'),
                subject: $pass,
                old: ['is_active' => ! $pass->is_active],
                new: ['is_active' => (bool) $pass->is_active],
                actor: $actor,
            );
        });
    }

    /**
     * The used-pass refusal lives in the controller (it maps to a 422); this
     * only runs once that guard has passed. The code is captured before the
     * delete because the audit row names the pass that is gone.
     */
    public function delete(EarlyPass $pass, ?User $actor): void
    {
        $values = $this->auditValues($pass);
        $code = $pass->code;

        DB::transaction(function () use ($pass, $values, $code, $actor) {
            $pass->delete();

            ActivityRecorder::record(
                action: 'early_pass_deleted',
                description: "Early access pass '{$code}' deleted",
                old: $values,
                actor: $actor,
            );
        });
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
