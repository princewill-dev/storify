<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\IndexJournalEntryRequest;
use App\Http\Requests\Management\Accounting\StoreJournalEntryRequest;
use App\Http\Resources\Management\Accounting\JournalEntryDetailResource;
use App\Http\Resources\Management\Accounting\JournalEntrySummaryResource;
use App\Models\JournalEntry;
use App\Repositories\Management\Accounting\JournalRepository;
use App\Services\Access\TenantGuard;
use App\Services\Accounting\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * WS-23 — Journal entries.
 *
 * Re-registers `accounting/journal` and `accounting/journal/{entry}` (feature
 * modules load after the shared route file, so this registration wins) and
 * adds the write workflow legacy had: create draft/posted, post draft, delete
 * draft, reverse posted.
 *
 * Money on the wire is integer kobo (`debit_kobo` / `credit_kobo`) per the
 * house rules — the legacy naira-float inputs are converted once by the SPA.
 *
 * Verify fix carried through: reversal is a two-part transition. It posts the
 * contra entry **and** voids the original, so reports that aggregate posted
 * entries never count a reversal twice.
 *
 * Layering: HTTP shape (statuses, messages, envelope, pagination meta) stays
 * here; field validation lives in App\Http\Requests\Management\Accounting,
 * queries/eager loads/aggregates in App\Repositories\Management\Accounting\
 * JournalRepository, the draft/post/reverse/delete workflows in
 * App\Services\Accounting\JournalService, and the payloads in
 * App\Http\Resources\Management\Accounting.
 */
class JournalController extends ApiController
{
    use ResolvesManagementContext;

    public function index(IndexJournalEntryRequest $request, JournalRepository $repository): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $filters = $request->validated();

        $entries = $repository->paginateForBusiness($businessId, $filters);

        // Filtered debit/credit totals across every matching entry, not just
        // the page the table happens to show.
        $totals = $repository->filteredTotals($businessId, $filters);

        return $this->ok(
            // `data` stays the flat row array the shared endpoint returned so
            // any existing consumer keeps working; the filtered totals ride in
            // `meta` beside the pagination keys.
            JournalEntrySummaryResource::collection($entries->getCollection())->resolve(),
            null,
            200,
            $this->paginationMeta($entries) + [
                'totals' => [
                    'debits' => (int) ($totals->debits ?? 0),
                    'credits' => (int) ($totals->credits ?? 0),
                ],
            ],
        );
    }

    public function show(Request $request, JournalEntry $entry, JournalRepository $repository): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        return $this->ok(['entry' => $this->detail($entry, $repository)]);
    }

    public function store(StoreJournalEntryRequest $request, JournalService $service, JournalRepository $repository): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        $validated = $request->validated();
        $lines = $service->normaliseLines($validated['lines'], $businessId);

        if ($request->boolean('save_as_draft')) {
            $entry = $service->saveDraft($businessId, $validated, $lines);

            return $this->ok(['entry' => $this->detail($entry, $repository)], 'Draft saved. Post it when ready.', 201);
        }

        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => 'A journal entry needs at least two lines.',
            ]);
        }

        $debits = (int) array_sum(array_column($lines, 'debit'));
        $credits = (int) array_sum(array_column($lines, 'credit'));

        if ($debits !== $credits) {
            throw ValidationException::withMessages([
                'lines' => 'Debits and credits must match before posting.',
            ]);
        }

        try {
            $entry = $service->post($businessId, $validated, $lines, $user->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        if (! $entry) {
            return $this->error('Nothing was posted — the entry has no amounts.');
        }

        Log::info('api.management.journal_posted_manually', [
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'total_debits' => $debits,
        ]);

        return $this->ok(['entry' => $this->detail($entry, $repository)], 'Journal entry posted.', 201);
    }

    public function postDraft(Request $request, JournalEntry $entry, JournalService $service, JournalRepository $repository): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->error('Only draft entries can be posted.');
        }

        $entry->loadMissing('lines');
        $debits = (int) $entry->lines->sum('debit_kobo');
        $credits = (int) $entry->lines->sum('credit_kobo');

        if ($entry->lines->count() < 2) {
            return $this->error('A journal entry needs at least two lines.');
        }

        if ($debits !== $credits || $debits <= 0) {
            return $this->error('Debits and credits must match before posting.');
        }

        try {
            $entry = $service->postDraft($entry, $this->user($request)->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(['entry' => $this->detail($entry->fresh(), $repository)], 'Entry posted.');
    }

    public function destroy(Request $request, JournalEntry $entry, JournalService $service): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->error('Only draft entries can be deleted. Use reversal for posted entries.');
        }

        $service->deleteDraft($entry);

        return $this->ok([], 'Draft deleted.');
    }

    public function reverse(Request $request, JournalEntry $entry, JournalService $service, JournalRepository $repository): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            return $this->error('Only posted entries can be reversed.');
        }

        try {
            $reversal = $service->reverse($entry, $this->user($request)->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        if (! $reversal) {
            return $this->error('This entry has no lines to reverse.');
        }

        return $this->ok([
            'entry' => $this->detail($reversal->fresh(), $repository),
            'reversed' => $this->detail($entry->fresh(), $repository),
        ], 'Reversing entry posted.', 201);
    }

    /**
     * Load and shape the detail payload. The reversed-by entry comes from the
     * repository; the resource only renders what it is given.
     *
     * @return array<string, mixed>
     */
    private function detail(JournalEntry $entry, JournalRepository $repository): array
    {
        $repository->loadForDetail($entry);

        return (new JournalEntryDetailResource($entry, $repository->reversedBy($entry)))->resolve();
    }

    private function authorizeEntry(Request $request, JournalEntry $entry): void
    {
        app(TenantGuard::class)->authorizeBusiness($entry, $this->user($request), 'You do not have access to this journal entry.');
    }
}
