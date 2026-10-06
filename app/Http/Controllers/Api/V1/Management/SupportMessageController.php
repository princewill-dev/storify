<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\SupportMessageReplyMail;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * WS-33 — support messaging.
 *
 * The legacy screen (`Management\SupportMessageController@index`) listed every
 * message belonging to the business's stores and, behind `support reply`,
 * could write a reply, flip the status and email the customer — but its Blade
 * view never rendered a reply form, so businesses could not actually use it.
 * This controller is that capability plus the reply UI it never had.
 *
 * Deliberate departures from the legacy:
 * - the legacy list was deliberately thin (no store/phone/status/reply); the
 *   payload below carries them so the SPA can be worked in, and adds q /
 *   status / store_id filters the legacy never had;
 * - ordering surfaces `pending` first. Legacy ordered `status asc`
 *   alphabetically, which put `closed` above `pending`;
 * - replies are refused on a closed conversation (admin-side) instead of
 *   silently overwriting it;
 * - soft-deleted stores are excluded, matching the WS-06 policy that deleted
 *   records must not leak back into read endpoints;
 * - the audit entry goes to `activity_logs` (ActivityLogger) so it is
 *   queryable, mirroring the legacy's Log::info intent without cloning the
 *   legacy log key that AGENTS.md forbids.
 */
class SupportMessageController extends ApiController
{
    use ResolvesManagementContext;

    private const STATUSES = ['pending', 'replied', 'closed'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $storeIds = $this->accessibleStoreIds($request);

        // Never trust an id from the request; an inaccessible store filter is
        // rejected rather than silently returning everything.
        if (($filters['store_id'] ?? null) !== null && ! $storeIds->contains((int) $filters['store_id'])) {
            return $this->error('Invalid store selection.', 422);
        }

        $messages = SupportMessage::query()
            ->whereIn('store_id', $storeIds)
            ->with('store')
            ->when($filters['q'] ?? null, function ($query, $term) {
                $term = trim((string) $term);
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%"));
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->orderByRaw("case status when 'pending' then 0 when 'replied' then 1 else 2 end")
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $replierNames = $this->replierNames($messages->getCollection());

        return $this->ok(
            [
                'messages' => $messages->getCollection()
                    ->map(fn (SupportMessage $message) => $this->payload($message, $replierNames))
                    ->values()
                    ->all(),
                // The store filter source. Legacy's view had no store column at
                // all; the SPA uses this for the filter and the no-stores state.
                'stores' => $this->accessibleStores($request)->map(fn (Store $store) => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                ])->values()->all(),
                'counts' => $this->counts($storeIds),
            ],
            null,
            200,
            $this->paginationMeta($messages),
        );
    }

    /**
     * Pending/total counts for the sidebar indicator and page header. WS-34's
     * shell-counts endpoint will eventually own shell badges; until then the
     * badge component reads this.
     */
    public function stats(Request $request): JsonResponse
    {
        return $this->ok(['counts' => $this->counts($this->accessibleStoreIds($request))]);
    }

    public function show(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizeMessage($request, $supportMessage);

        $supportMessage->loadMissing('store');

        return $this->ok([
            'message' => $this->payload($supportMessage, $this->replierNames(collect([$supportMessage]))),
        ]);
    }

    public function reply(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizeMessage($request, $supportMessage);

        $data = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        // A closed conversation is admin-final; legacy would overwrite it.
        if ($supportMessage->status === 'closed') {
            return $this->error('This conversation has been closed and can no longer be replied to.', 422);
        }

        $user = $this->user($request);

        DB::transaction(function () use ($supportMessage, $data, $user) {
            $supportMessage->update([
                'reply' => $data['reply'],
                'status' => 'replied',
                'replied_by_type' => 'business',
                'replied_by_id' => $user->id,
                'replied_at' => now(),
            ]);
        });

        ActivityLogger::log(
            'support_message_replied',
            'Business replied to a support message from '.$supportMessage->name,
            [
                'support_message_id' => $supportMessage->id,
                'store_id' => $supportMessage->store_id,
            ],
            $user->id,
        );

        $fresh = $supportMessage->fresh()->load('store');

        // The status flip above is the durable record; a transient mail failure
        // must not lose the reply (legacy wrapped the queue call the same way).
        try {
            Mail::to($fresh->email)->queue(new SupportMessageReplyMail($fresh));

            Log::info('support.message.reply_email_queued', [
                'message_id' => $fresh->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('support.message.reply_email_failed', [
                'message_id' => $fresh->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok(
            ['message' => $this->payload($fresh, $this->replierNames(collect([$fresh])))],
            'Reply sent successfully to the customer.',
        );
    }

    /**
     * @param  Collection<int, int>  $storeIds
     * @return array{pending: int, replied: int, closed: int, total: int}
     */
    private function counts(Collection $storeIds): array
    {
        $query = SupportMessage::query()->whereIn('store_id', $storeIds);

        return [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'replied' => (clone $query)->where('status', 'replied')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * Stores the caller may read messages for. Legacy scoped by
     * `Store::where('user_id', $user->id)`, which silently gave staff nothing;
     * accessibleStores() covers owners and staff alike, minus deleted stores.
     *
     * @return Collection<int, Store>
     */
    private function accessibleStores(Request $request): Collection
    {
        $query = $this->user($request)->accessibleStores();

        return $query
            ->where($query->getModel()->qualifyColumn('status'), '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, int>
     */
    private function accessibleStoreIds(Request $request): Collection
    {
        return $this->accessibleStores($request)->pluck('id');
    }

    private function authorizeMessage(Request $request, SupportMessage $supportMessage): void
    {
        if (! $this->accessibleStoreIds($request)->contains((int) $supportMessage->store_id)) {
            abort(403, 'You do not have access to this support message.');
        }
    }

    /**
     * `replied_by_id` carries no relation on the model, so resolve the names in
     * one query per page instead of an N+1 (or touching a shared model).
     *
     * @param  Collection<int, SupportMessage>  $messages
     * @return array<int, string>
     */
    private function replierNames(Collection $messages): array
    {
        $ids = $messages
            ->where('replied_by_type', 'business')
            ->pluck('replied_by_id')
            ->filter()
            ->unique();

        return $ids->isEmpty() ? [] : User::whereIn('id', $ids)->pluck('name', 'id')->all();
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
            'replied_by_id' => $message->replied_by_id,
            'replied_by_name' => $message->replied_by_type === 'business'
                ? ($replierNames[$message->replied_by_id] ?? null)
                : null,
            'replied_at' => $message->replied_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'store' => $message->store ? [
                'id' => $message->store->id,
                'store_id' => $message->store->store_id,
                'name' => $message->store->name,
            ] : null,
        ];
    }
}
