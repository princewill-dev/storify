<?php

namespace App\Http\Resources\Admin;

use App\Models\ActivityLog;
use App\Services\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-1 — the activity-log viewer row: the detail columns legacy captured but
 * never showed (subject, old/new values, metadata, business) plus the actor
 * block the list renders.
 *
 * @property-read ActivityLog $resource
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ActivityLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            // Redacted again on read: rows written before ActivityRecorder
            // existed (and by other writers) may carry raw secrets.
            'old_values' => $log->old_values === null ? null : ActivityRecorder::redact($log->old_values),
            'new_values' => $log->new_values === null ? null : ActivityRecorder::redact($log->new_values),
            'metadata' => ActivityRecorder::redact($log->metadata ?? []),
            'business_id' => $log->business_id,
            'business' => $log->business?->name,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'account_code' => $log->user->account_code,
                'role' => $log->user->role,
            ] : null,
            'created_at' => $log->created_at?->toISOString(),
        ];
    }
}
