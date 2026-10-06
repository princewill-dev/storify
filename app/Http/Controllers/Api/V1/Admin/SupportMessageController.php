<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\SupportMessageReplyMail;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * WS-16 (admin console) — the platform support inbox.
 *
 * Legacy `/office/support-messages` (Content › Support Messages) rendered
 * every message across every store on one page with pending/replied counters,
 * a detail modal, a reply action that emailed the customer and a hard delete.
 * This controller reproduces that capability and fixes the defects the audit
 * named rather than cloning them:
 *
 *  - pagination, search, status/store/date filters and sorting (legacy dumped
 *    the whole table; sort columns are whitelisted, never raw `sort_by`);
 *  - explicit re-reply semantics: legacy's reply endpoint silently overwrote
 *    an existing reply (the UI merely hid the button), so a second POST now
 *    needs a deliberate `reopen` first — the overwrite it prevents is defect
 *    #16 in the consolidation list;
 *  - a real "Close" action, so the schema's `closed` enum stops being dead;
 *  - pending-first ordering (legacy ordered `status` *alphabetically*, which
 *    would have buried `pending` under `closed` the moment anything set it);
 *  - an `EnsuresPlatformAdmin` guard on every action (WS-5 shape): the
 *    audience token plus `admin.support` are not enough on their own because
 *    in-business "Super Admin" roles carry the full admin.* bundle;
 *  - every mutation writes an ActivityRecorder row with old/new values.
 *
 * Thread ownership: `support_messages` has a single reply slot shared by the
 * business and the admin side. `replied_by_type`/`replied_by_id`/`replied_at`
 * say who answered last, and the payload labels it ("Admin" / "Business").
 * Whoever wants the slot back (or wants to answer after a close) re-opens the
 * conversation first; a re-reply overwrites the column by design, and the
 * previous text survives in the audit row's old_values.
 */
class SupportMessageController extends ApiController
{
    use EnsuresPlatformAdmin;

    private const STATUSES = ['pending', 'replied', 'closed'];

    /**
     * Whitelisted sort columns. Anything else falls back to the inbox
     * ordering (pending first, then the given column).
     */
    private const SORTS = ['created_at', 'updated_at', 'id', 'status'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim((string) ($filters['q'] ?? ''));
        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        $messages = SupportMessage::query()
            ->with(['store.business:id,name,business_code'])
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")
                ->orWhere('reply', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            // Unanswered work first — what an inbox is for.
            ->orderByRaw("case status when 'pending' then 0 when 'replied' then 1 else 2 end")
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $replierNames = $this->replierNames($messages->getCollection());

        return $this->ok(
            [
                'messages' => $messages->getCollection()
                    ->map(fn (SupportMessage $message) => $this->payload($message, $replierNames))
                    ->values()
                    ->all(),
                // Filter source: only stores that actually have messages, so a
                // platform-wide dropdown stays relevant (legacy had none at all).
                'stores' => $this->storeOptions(),
                'counts' => $this->counts(),
            ],
            null,
            200,
            $this->paginationMeta($messages),
        );
    }

    /**
     * Pending/replied/closed counters for the page chips and the nav badge.
     * Platform-wide, independent of the active filters (legacy chips were
     * global too).
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(['counts' => $this->counts()]);
    }

    public function show(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $supportMessage->loadMissing('store.business');

        return $this->ok([
            'message' => $this->payload($supportMessage, $this->replierNames(collect([$supportMessage]))),
        ]);
    }

    public function reply(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        // A closed thread is final until re-opened (the management API refuses
        // the same way, so both ends of the shared table agree).
        if ($supportMessage->status === 'closed') {
            return $this->error('This conversation is closed. Re-open it before replying.', 422);
        }

        // Explicit re-reply guard: legacy let a second POST silently overwrite
        // the first reply. A follow-up is now a deliberate re-open.
        if ($supportMessage->status === 'replied') {
            return $this->error('This message has already been replied to. Re-open the conversation to send a follow-up reply.', 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($supportMessage, $data, $user) {
            $old = $this->auditValues($supportMessage);

            $supportMessage->update([
                'reply' => $data['reply'],
                'status' => 'replied',
                'replied_by_type' => 'admin',
                'replied_by_id' => $user->id,
                'replied_at' => now(),
            ]);

            ActivityRecorder::record(
                action: 'support_message_replied',
                description: 'Admin replied to a support message from '.$supportMessage->name,
                subject: $supportMessage,
                old: $old,
                new: $this->auditValues($supportMessage),
                actor: $user,
            );
        });

        $fresh = $supportMessage->fresh()->load('store.business');
        $queued = $this->queueReplyMail($fresh, (int) $user->id);

        return $this->ok(
            [
                'message' => $this->payload($fresh, $this->replierNames(collect([$fresh]))),
                'email_queued' => $queued,
            ],
            // Honest surface: the reply is durable either way, but the toast
            // must not claim the customer was emailed if queueing failed.
            $queued
                ? 'Reply sent successfully to the customer.'
                : 'Reply saved, but the customer email could not be queued. Check the mail queue.',
        );
    }

    /**
     * Close a conversation without replying (or after replying). Revives the
     * schema's `closed` value, which no legacy code path ever set.
     */
    public function close(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($supportMessage->status === 'closed') {
            return $this->error('This conversation is already closed.', 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($supportMessage, $user) {
            $old = $this->auditValues($supportMessage);
            $supportMessage->update(['status' => 'closed']);

            ActivityRecorder::record(
                action: 'support_message_closed',
                description: 'Admin closed the support conversation with '.$supportMessage->name,
                subject: $supportMessage,
                old: $old,
                new: $this->auditValues($supportMessage),
                actor: $user,
            );
        });

        $fresh = $supportMessage->fresh()->load('store.business');

        return $this->ok(
            ['message' => $this->payload($fresh, $this->replierNames(collect([$fresh])))],
            'Conversation closed.',
        );
    }

    /**
     * Re-open a replied or closed conversation so a follow-up reply is
     * possible. The previous reply text is kept as context for the operator
     * (the next reply overwrites it; the audit row keeps the old text).
     */
    public function reopen(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($supportMessage->status === 'pending') {
            return $this->error('This conversation is already open.', 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($supportMessage, $user) {
            $old = $this->auditValues($supportMessage);
            $supportMessage->update(['status' => 'pending']);

            ActivityRecorder::record(
                action: 'support_message_reopened',
                description: 'Admin re-opened the support conversation with '.$supportMessage->name,
                subject: $supportMessage,
                old: $old,
                new: $this->auditValues($supportMessage),
                actor: $user,
            );
        });

        $fresh = $supportMessage->fresh()->load('store.business');

        return $this->ok(
            ['message' => $this->payload($fresh, $this->replierNames(collect([$fresh])))],
            'Conversation re-opened; you can send a follow-up reply now.',
        );
    }

    public function destroy(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The row is hard-deleted (legacy had no soft delete); capture what it
        // held first so the activity log still explains what left the inbox.
        $values = $this->auditValues($supportMessage);
        $messageId = $supportMessage->id;
        $metadata = [
            'support_message_id' => $messageId,
            'store_id' => $supportMessage->store_id,
            'customer_email' => $supportMessage->email,
            'message' => $supportMessage->message,
        ];
        $user = $request->user();

        DB::transaction(function () use ($supportMessage, $values, $metadata, $messageId, $user) {
            $supportMessage->delete();

            ActivityRecorder::record(
                action: 'support_message_deleted',
                description: 'Support message #'.$messageId.' deleted',
                old: $values,
                metadata: $metadata,
                actor: $user,
            );
        });

        return $this->ok([], 'Support message deleted successfully.');
    }

    /**
     * @return array{pending: int, replied: int, closed: int, total: int}
     */
    private function counts(): array
    {
        $counts = SupportMessage::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $pending = $counts['pending'] ?? 0;
        $replied = $counts['replied'] ?? 0;
        $closed = $counts['closed'] ?? 0;

        return [
            'pending' => $pending,
            'replied' => $replied,
            'closed' => $closed,
            'total' => $pending + $replied + $closed,
        ];
    }

    /**
     * Distinct stores that actually hold messages — the filter dropdown
     * source. Deleted stores stay in the list: their messages are still owed
     * an answer, and the payload marks the store's status so the SPA can say so.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeOptions(): array
    {
        $storeIds = SupportMessage::query()->distinct()->pluck('store_id');

        return Store::query()
            ->with(['business:id,name,business_code'])
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $store->status,
                'business_name' => $store->business?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * `replied_by_id` carries no relation on the model, so resolve the names
     * in one query per page rather than N+1 (or touching a shared model).
     * Both admins and business users live in the `users` table.
     *
     * @param  Collection<int, SupportMessage>  $messages
     * @return array<int, string>
     */
    private function replierNames(Collection $messages): array
    {
        $ids = $messages
            ->pluck('replied_by_id')
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, string>  $replierNames
     * @return array<string, mixed>
     */
    private function payload(SupportMessage $message, array $replierNames = []): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'phone' => $message->phone,
            'message' => $message->message,
            'status' => $message->status,
            'reply' => $message->reply,
            'replied_by_type' => $message->replied_by_type,
            // Legacy's detail modal rendered "Reply (Admin)" / "Reply (Business)".
            'replied_by_type_label' => match ($message->replied_by_type) {
                'admin' => 'Admin',
                'business' => 'Business',
                null => null,
                default => ucfirst((string) $message->replied_by_type),
            },
            'replied_by_id' => $message->replied_by_id,
            'replied_by_name' => $message->replied_by_id !== null
                ? ($replierNames[$message->replied_by_id] ?? null)
                : null,
            'replied_at' => $message->replied_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'store' => $message->store ? [
                'id' => $message->store->id,
                'store_id' => $message->store->store_id,
                'name' => $message->store->name,
                'status' => $message->store->status,
                'business' => $message->store->business ? [
                    'id' => $message->store->business->id,
                    'name' => $message->store->business->name,
                    'business_code' => $message->store->business->business_code,
                ] : null,
            ] : null,
            // Up-front action state, so the SPA disables buttons instead of
            // round-tripping the refusals (the same rule the reply guard applies).
            'can' => [
                'reply' => $message->status === 'pending',
                'close' => $message->status !== 'closed',
                'reopen' => $message->status !== 'pending',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(SupportMessage $message): array
    {
        return [
            'status' => $message->status,
            'reply' => $message->reply,
            'replied_by_type' => $message->replied_by_type,
            'replied_by_id' => $message->replied_by_id,
            'replied_at' => $message->replied_at?->toISOString(),
        ];
    }

    /**
     * The status flip is the durable record; a transient mail failure must not
     * lose the reply (legacy wrapped the queue call the same way). Returns
     * whether the customer email made it onto the queue so the response can be
     * honest about it.
     */
    private function queueReplyMail(SupportMessage $message, int $adminId): bool
    {
        try {
            Mail::to($message->email)->queue(new SupportMessageReplyMail($message));

            Log::info('support.message.reply_email_queued', [
                'message_id' => $message->id,
                'admin_id' => $adminId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('support.message.reply_email_failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
