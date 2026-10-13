<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\BusinessNotification;
use App\Models\User;
use App\Services\Payments\Custody\CustodyNotifier;
use App\Support\Money\Naira;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-49 — the in-app half of a business's money alerts.
 *
 * Every custody event writes one {@see BusinessNotification} row and mails it —
 * see {@see CustodyNotifier}. The row is the
 * exactly-once guard *and* the record, which is why it is readable here: the mail
 * can fail (a refused SMTP connection is recorded on the row as `mail_error` and
 * the event is still announced), and a business that reads its alerts only in a
 * mailbox has no record in the product at all.
 *
 * ## No permission gate, and why
 *
 * A payout alert says something about the business's money, so it is tempting to
 * hang it behind `transactions view` like the wallet and the payout list. It is
 * gated by tenancy instead, because an alert is addressed to a *person*: the
 * notifier writes `user_id` when it can — the owner, or whoever the event was
 * about — and a role that cannot see transactions still needs to be told that the
 * money it is holding arrived. The tenancy scope is what protects it, and it is
 * enforced on every row here by `business_id`.
 */
class NotificationController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $notifications = $this->query($user)
            ->when($request->boolean('unread_only'), fn (Builder $q) => $q->whereNull('read_at'))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 20))));

        return $this->ok(
            $notifications->getCollection()->map(fn (BusinessNotification $n) => $this->row($n))->values()->all(),
            null,
            200,
            $this->paginationMeta($notifications) + [
                // The badge count, and the reason it is a separate query rather
                // than a sum over the page: the sidebar renders it while the list
                // shows one page of twenty, and a count derived from the page
                // would read 0 on every page after the first.
                'unread_count' => $this->unreadCount($user),
            ],
        );
    }

    /**
     * Mark everything the business has been told as read.
     *
     * A single statement over the whole set rather than a loop over the ids the
     * client holds: the client's idea of what is unread is a page it fetched, and
     * a notification written between that fetch and this call would be missed and
     * stay bold forever.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $updated = $this->query($user)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->ok([
            'marked_read' => $updated,
            'unread_count' => 0,
        ], $updated === 1 ? '1 notification marked as read.' : $updated.' notifications marked as read.');
    }

    /**
     * Mark one read. Idempotent: re-reading an alert the business has already
     * opened is a no-op rather than a second timestamp, and the response says the
     * same thing either way, because "it is read now" is what the caller asked
     * for and what is true.
     */
    public function markRead(Request $request, BusinessNotification $notification): JsonResponse
    {
        $this->authorizeNotification($request, $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $this->ok([
            'notification' => $this->row($notification->refresh()),
            'unread_count' => $this->unreadCount($this->user($request)),
        ], 'Notification marked as read.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(BusinessNotification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'type' => $notification->type,
            'level' => $notification->level,
            'title' => $notification->title,
            'body' => $notification->body,
            'amount' => Naira::floatFromKobo((int) $notification->amount_kobo),
            'amount_kobo' => (int) $notification->amount_kobo,
            'currency' => $notification->currency,
            // What it is about, so a client can link straight to the sale or the
            // payout. Null-tolerant: the subject can be gone while the record of
            // having been told about it stays — see the model.
            'subject_type' => $notification->subject_type,
            'subject_id' => $notification->subject_id !== null ? (int) $notification->subject_id : null,
            // The same fact as a word — `transaction`, `payout`, `business` — so a
            // client picks a destination without string-matching a namespace. The
            // morph class is a class name and will move if the model does; this is
            // the part of it a client is entitled to depend on.
            'subject_kind' => $this->subjectKind($notification->subject_type),
            'metadata' => $notification->metadata,
            // The mail half, on the record: a business that did not receive the
            // email can see that it was never sent rather than wondering.
            'emailed_at' => $notification->emailed_at?->toISOString(),
            'mail_error' => $notification->mail_error,
            'read_at' => $notification->read_at?->toISOString(),
            'read' => ! $notification->unread(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    /**
     * The morph class, reduced to the word a client can route on.
     *
     * `App\Models\Payout` → `payout`, and null stays null: an alert whose subject
     * has been deleted keeps its record and loses its destination, which is the
     * honest pair. Anything unrecognised is passed through lower-cased rather
     * than dropped — a new subject type should reach the client, not vanish.
     */
    private function subjectKind(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        return strtolower(class_basename($subjectType));
    }

    private function query(User $user): Builder
    {
        return BusinessNotification::query()->where('business_id', $user->business_id);
    }

    private function unreadCount(User $user): int
    {
        return (clone $this->query($user))->whereNull('read_at')->count();
    }

    /**
     * Route-model binding resolves any id in the table, so this is the only thing
     * standing between a business and another business's alerts — including the
     * chance to mark them read.
     */
    private function authorizeNotification(Request $request, BusinessNotification $notification): void
    {
        if ((int) $notification->business_id !== (int) $this->user($request)->business_id) {
            abort(404, 'Notification not found.');
        }
    }
}
