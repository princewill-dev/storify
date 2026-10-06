<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
 */
class JournalController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly LedgerPostingService $posting,
        private readonly LedgerSetupService $setup,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->user($request)->business_id;

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                JournalEntry::STATUS_DRAFT,
                JournalEntry::STATUS_POSTED,
                JournalEntry::STATUS_VOID,
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = JournalEntry::query()
            ->where('business_id', $businessId)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('entry_date', '<=', $to))
            // Legacy only searched entry_number; reference and memo were in
            // the placeholder but not in the query.
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';

                $q->where(fn ($inner) => $inner
                    ->where('entry_number', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('memo', 'like', $like));
            });

        $entries = (clone $base)
            ->withCount('lines')
            ->withSum('lines as total_debits', 'debit_kobo')
            ->withSum('lines as total_credits', 'credit_kobo')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        // Filtered debit/credit totals across every matching entry, not just
        // the page the table happens to show.
        $totals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.id', (clone $base)->select('id'))
            ->selectRaw('COALESCE(SUM(journal_lines.debit_kobo), 0) as debits, COALESCE(SUM(journal_lines.credit_kobo), 0) as credits')
            ->first();

        return $this->ok(
            // `data` stays the flat row array the shared endpoint returned so
            // any existing consumer keeps working; the filtered totals ride in
            // `meta` beside the pagination keys.
            $entries->getCollection()->map(fn (JournalEntry $entry) => $this->summary($entry))->all(),
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

    public function show(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        return $this->ok(['entry' => $this->detail($entry)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'save_as_draft' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ledger_account_id' => ['required', 'integer'],
            'lines.*.debit_kobo' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_kobo' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = $this->normalisedLines($validated['lines'], $businessId);

        if ($request->boolean('save_as_draft')) {
            $entry = DB::transaction(function () use ($businessId, $validated, $lines) {
                $entry = JournalEntry::create([
                    'business_id' => $businessId,
                    'entry_date' => $validated['entry_date'],
                    'memo' => $validated['memo'] ?? null,
                    'reference' => $validated['reference'] ?? null,
                    'status' => JournalEntry::STATUS_DRAFT,
                ]);

                foreach ($lines as $line) {
                    $entry->lines()->create([
                        'ledger_account_id' => $line['account_id'],
                        'description' => $line['description'],
                        'debit_kobo' => $line['debit'],
                        'credit_kobo' => $line['credit'],
                        'currency' => 'NGN',
                    ]);
                }

                return $entry;
            });

            return $this->ok(['entry' => $this->detail($entry)], 'Draft saved. Post it when ready.', 201);
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
            $entry = $this->posting->post($businessId, $lines, [
                'memo' => $validated['memo'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'user_id' => $user->id,
                'date' => $validated['entry_date'],
            ]);
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

        return $this->ok(['entry' => $this->detail($entry)], 'Journal entry posted.', 201);
    }

    public function postDraft(Request $request, JournalEntry $entry): JsonResponse
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
            $period = $this->setup->resolveOpenPeriod($entry->business_id, $entry->entry_date->toDateString());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        $entry->update([
            'status' => JournalEntry::STATUS_POSTED,
            'fiscal_period_id' => $period->id,
            'posted_at' => now(),
            'posted_by' => $this->user($request)->id,
        ]);

        return $this->ok(['entry' => $this->detail($entry->fresh())], 'Entry posted.');
    }

    public function destroy(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->error('Only draft entries can be deleted. Use reversal for posted entries.');
        }

        DB::transaction(function () use ($entry) {
            $entry->lines()->delete();
            $entry->delete();
        });

        return $this->ok([], 'Draft deleted.');
    }

    public function reverse(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            return $this->error('Only posted entries can be reversed.');
        }

        try {
            $reversal = $this->posting->reverseEntry($entry, 'Reversal of '.$entry->entry_number, $this->user($request)->id);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        if (! $reversal) {
            return $this->error('This entry has no lines to reverse.');
        }

        return $this->ok([
            'entry' => $this->detail($reversal->fresh()),
            'reversed' => $this->detail($entry->fresh()),
        ], 'Reversing entry posted.', 201);
    }

    /**
     * Drop blank rows, refuse debit-and-credit lines and unknown accounts.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array{account_id: int, debit: int, credit: int, description: ?string}>
     */
    private function normalisedLines(array $rawLines, int $businessId): array
    {
        $lines = [];

        foreach ($rawLines as $index => $line) {
            $debit = (int) ($line['debit_kobo'] ?? 0);
            $credit = (int) ($line['credit_kobo'] ?? 0);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.credit_kobo" => 'A journal line cannot have both a debit and a credit.',
                ]);
            }

            $lines[] = [
                'account_id' => (int) $line['ledger_account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if (empty($lines)) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one line with an amount.',
            ]);
        }

        $referenced = array_unique(array_column($lines, 'account_id'));

        $owned = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $referenced)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($referenced, $owned)) {
            throw ValidationException::withMessages([
                'lines' => 'One or more accounts are invalid.',
            ]);
        }

        return $lines;
    }

    private function authorizeEntry(Request $request, JournalEntry $entry): void
    {
        if ((int) $entry->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this journal entry.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'lines_count' => (int) $entry->lines_count,
            'total_debits' => (int) ($entry->total_debits ?? 0),
            'total_credits' => (int) ($entry->total_credits ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(JournalEntry $entry): array
    {
        $entry->loadMissing(['lines.account', 'fiscalPeriod', 'postedBy', 'reversalOf']);

        // The forward link legacy never had: a void original points at the
        // entry that reversed it so the detail screen can explain the void.
        $reversedBy = JournalEntry::query()
            ->where('reversal_of_id', $entry->id)
            ->orderBy('id')
            ->first();

        $debits = (int) $entry->lines->sum('debit_kobo');
        $credits = (int) $entry->lines->sum('credit_kobo');

        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'memo' => $entry->memo,
            'reference' => $entry->reference,
            'status' => $entry->status,
            'fiscal_period' => $entry->fiscalPeriod?->name,
            'posted_by' => $entry->postedBy?->name,
            'posted_at' => $entry->posted_at?->toISOString(),
            'voided_at' => $entry->voided_at?->toISOString(),
            'reversal_of' => $entry->reversalOf ? [
                'id' => $entry->reversalOf->id,
                'entry_number' => $entry->reversalOf->entry_number,
            ] : null,
            'reversed_by' => $reversedBy ? [
                'id' => $reversedBy->id,
                'entry_number' => $reversedBy->entry_number,
            ] : null,
            'total_debits' => $debits,
            'total_credits' => $credits,
            'is_balanced' => $debits === $credits,
            'lines' => $entry->lines->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'ledger_account_id' => $line->ledger_account_id,
                'account' => $line->account?->name,
                'account_code' => $line->account?->code,
                'description' => $line->description,
                'debit' => (int) $line->debit_kobo,
                'credit' => (int) $line->credit_kobo,
            ])->values()->all(),
        ];
    }
}
