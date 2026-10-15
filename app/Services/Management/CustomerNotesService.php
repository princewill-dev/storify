<?php

namespace App\Services\Management;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WS-56 — the internal notes a business keeps about a customer.
 *
 * Each write commits the note and its audit row inside one transaction, so a
 * failed audit entry rolls the note back with it — the same rule
 * CustomerLifecycleService follows for the customer's own transitions.
 *
 * The audit row is not a duplicate of the note. The note says what was
 * decided; the audit row says who wrote it and when, in the same list as every
 * other customer event. A note deleted by mistake leaves that trail.
 */
final class CustomerNotesService
{
    public function write(Customer $customer, User $author, string $body, Request $request): CustomerNote
    {
        return DB::transaction(function () use ($customer, $author, $body, $request) {
            $note = $customer->notes()->create([
                'business_id' => $customer->business_id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            ActivityLog::create([
                'user_id' => $author->id,
                'business_id' => $customer->business_id,
                'action' => 'customer_note_added',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => 'Note added for '.$customer->full_name,
                'new_values' => ['note_id' => $note->id],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            return $note;
        });
    }

    /**
     * A note is deleted, not soft-kept: it was wrong, and a wrong note shown
     * beside a right one is worse than no note. The audit row is what remains,
     * so the deletion itself is visible.
     */
    public function remove(CustomerNote $note, User $actor, Request $request): void
    {
        DB::transaction(function () use ($note, $actor, $request) {
            $customer = $note->customer;

            $note->delete();

            ActivityLog::create([
                'user_id' => $actor->id,
                'business_id' => $customer?->business_id,
                'action' => 'customer_note_deleted',
                'subject_type' => Customer::class,
                'subject_id' => $customer?->id,
                'description' => 'Note deleted for '.($customer?->full_name ?? 'a customer'),
                'old_values' => ['note_id' => $note->id, 'body' => $note->body],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        });
    }
}
