<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Http\SensitiveKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * WS-1 (admin console) — the single audit entry point.
 *
 * Every admin mutation records through here so the activity-log viewer sees
 * one shape: actor, business, subject, old/new values, metadata, IP and user
 * agent. The pre-existing ActivityLogger only carried action/description/
 * metadata and filtered sensitive keys at the top level; this recorder adds
 * the subject columns and redacts recursively, so a nested `api_keys` or
 * `password` value can never be persisted (the legacy settings screen kept
 * api_keys out of the log by hand — this makes that structural).
 *
 * Later workstreams call it like:
 *
 *     ActivityRecorder::record(
 *         action: 'business_suspended',
 *         description: "Business {$business->name} suspended",
 *         subject: $business,
 *         old: ['status' => 'active'],
 *         new: ['status' => 'suspended'],
 *         metadata: ['reason' => $reason],
 *     );
 *
 * Callers write inside their transaction, so a failed audit row rolls the
 * mutation back with it — the trail must never silently drop an event.
 */
class ActivityRecorder
{
    public const REDACTED = SensitiveKeys::REDACTED;

    /**
     * Record an audit row.
     *
     * @param  array<string, mixed>  $old  Values before the mutation (redacted)
     * @param  array<string, mixed>  $new  Values after the mutation (redacted)
     * @param  array<string, mixed>  $metadata  Extra context (redacted)
     */
    public static function record(
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
        ?User $actor = null,
        ?int $businessId = null,
    ): ActivityLog {
        if ($actor === null) {
            $current = Auth::user();
            $actor = $current instanceof User ? $current : null;
        }

        // Business resolution: explicit argument, then the actor's business,
        // then the subject's (admin rows are platform-wide and stay null).
        // The businesses table has no business_id column — for a Business
        // subject the subject *is* the tenant.
        if ($businessId === null && $actor?->business_id) {
            $businessId = (int) $actor->business_id;
        }

        if ($businessId === null && $subject !== null) {
            $subjectBusinessId = $subject instanceof Business
                ? $subject->getKey()
                : $subject->getAttribute('business_id');

            if ($subjectBusinessId !== null) {
                $businessId = (int) $subjectBusinessId;
            }
        }

        return ActivityLog::create([
            'user_id' => $actor?->id,
            'business_id' => $businessId,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old === [] ? null : self::redact($old),
            'new_values' => $new === [] ? null : self::redact($new),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'metadata' => self::redact($metadata),
        ]);
    }

    /**
     * Recursively replace sensitive values so they can be shown safely.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        return SensitiveKeys::redact($values);
    }
}
