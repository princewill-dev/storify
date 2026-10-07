<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListSupportMessagesRequest;
use App\Http\Requests\Admin\ReplySupportMessageRequest;
use App\Http\Resources\Admin\SupportMessageResource;
use App\Http\Resources\Admin\SupportMessageStoreOptionResource;
use App\Models\SupportMessage;
use App\Repositories\Admin\SupportMessageRepository;
use App\Services\Admin\SupportInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. The filter/reply validation lives in the
 * Admin FormRequests, the inbox query and its aggregates in
 * SupportMessageRepository, the transaction/audit/mail workflows in
 * SupportInboxService, and the row/store-option shapes in
 * SupportMessageResource and SupportMessageStoreOptionResource. The status
 * refusals and the platform-admin guard stay here: their order and copy are
 * asserted behaviour.
 */
class SupportMessageController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly SupportMessageRepository $messages,
        private readonly SupportInboxService $support,
    ) {}

    public function index(ListSupportMessagesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $messages = $this->messages->paginateInbox($request->validated());

        $replierNames = $this->messages->replierNames($messages->getCollection());

        return $this->ok(
            [
                'messages' => $messages->getCollection()
                    ->map(fn (SupportMessage $message) => $this->payload($message, $replierNames))
                    ->values()
                    ->all(),
                // Filter source: only stores that actually have messages, so a
                // platform-wide dropdown stays relevant (legacy had none at all).
                'stores' => SupportMessageStoreOptionResource::collection($this->messages->storeOptions())->resolve(),
                'counts' => $this->messages->counts(),
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

        return $this->ok(['counts' => $this->messages->counts()]);
    }

    public function show(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $supportMessage->loadMissing('store.business');

        return $this->ok([
            'message' => $this->payload($supportMessage, $this->messages->replierNames(collect([$supportMessage]))),
        ]);
    }

    public function reply(ReplySupportMessageRequest $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

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

        $result = $this->support->reply($supportMessage, $data['reply'], $user);

        return $this->ok(
            [
                'message' => $this->payload($result['message'], $this->messages->replierNames(collect([$result['message']]))),
                'email_queued' => $result['email_queued'],
            ],
            // Honest surface: the reply is durable either way, but the toast
            // must not claim the customer was emailed if queueing failed.
            $result['email_queued']
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

        $fresh = $this->support->close($supportMessage, $request->user());

        return $this->ok(
            ['message' => $this->payload($fresh, $this->messages->replierNames(collect([$fresh])))],
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

        $fresh = $this->support->reopen($supportMessage, $request->user());

        return $this->ok(
            ['message' => $this->payload($fresh, $this->messages->replierNames(collect([$fresh])))],
            'Conversation re-opened; you can send a follow-up reply now.',
        );
    }

    public function destroy(Request $request, SupportMessage $supportMessage): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->support->delete($supportMessage, $request->user());

        return $this->ok([], 'Support message deleted successfully.');
    }

    /**
     * The row/thread shape, kept as a thin private seam so the response sites
     * read as they did before the extraction; the fields live in
     * SupportMessageResource.
     *
     * @param  array<int, string>  $replierNames
     * @return array<string, mixed>
     */
    private function payload(SupportMessage $message, array $replierNames = []): array
    {
        return SupportMessageResource::make($message, $replierNames)->resolve();
    }
}
