<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        $entries = JournalEntry::query()
            ->where('business_id', $businessId)
            ->withCount('lines')
            ->with(['lines' => fn ($q) => $q->selectRaw('journal_entry_id, SUM(debit_kobo) as debit, SUM(credit_kobo) as credit')->groupBy('journal_entry_id')])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('entry_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('entry_date', '<=', $request->date('to')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($inner) => $inner->where('entry_number', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('memo', 'like', $term));
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('management.accounting.journal.index', compact('entries'));
    }

    public function show(Request $request, JournalEntry $entry): View
    {
        $this->authorizeEntry($request, $entry);

        $entry->load(['lines.account', 'lines.store', 'postedBy', 'reversalOf', 'fiscalPeriod']);

        return view('management.accounting.journal.show', compact('entry'));
    }

    public function create(Request $request): View
    {
        $businessId = $request->user()->business_id;

        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->orderBy('code')
            ->get();

        return view('management.accounting.journal.create', compact('accounts'));
    }

    public function store(Request $request, LedgerPostingService $posting, \App\Services\Accounting\LedgerSetupService $setup): RedirectResponse
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'save_as_draft' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ledger_account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = [];
        foreach ($validated['lines'] as $line) {
            $debit = (int) round(((float) ($line['debit'] ?? 0)) * 100);
            $credit = (int) round(((float) ($line['credit'] ?? 0)) * 100);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $lines[] = [
                'account_id' => (int) $line['ledger_account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        if (count($lines) < 1) {
            return back()->withInput()->with('error', 'Add at least one line with an amount.');
        }

        // Ensure all accounts belong to the business
        $validIds = LedgerAccount::where('business_id', $businessId)
            ->whereIn('id', array_column($lines, 'account_id'))
            ->pluck('id')
            ->all();

        if (count($validIds) !== count(array_unique(array_column($lines, 'account_id')))) {
            return back()->withInput()->with('error', 'One or more accounts are invalid.');
        }

        $isDraft = $request->boolean('save_as_draft');

        if ($isDraft) {
            $entry = DB::transaction(function () use ($businessId, $validated, $lines, $request) {
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

            return redirect()->route('management.accounting.journal.show', $entry)
                ->with('success', 'Draft saved. Post it when ready.');
        }

        if (count($lines) < 2) {
            return back()->withInput()->with('error', 'A journal entry needs at least two lines.');
        }

        $debitTotal = array_sum(array_column($lines, 'debit'));
        $creditTotal = array_sum(array_column($lines, 'credit'));

        if ($debitTotal !== $creditTotal) {
            return back()->withInput()->with('error', 'Debits and credits must match before posting.');
        }

        try {
            $posting->post($businessId, $lines, [
                'memo' => $validated['memo'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'user_id' => $request->user()->id,
                'date' => $validated['entry_date'],
            ]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('management.accounting.journal.index')
            ->with('success', 'Journal entry posted.');
    }

    public function postDraft(Request $request, JournalEntry $entry, \App\Services\Accounting\LedgerSetupService $setup): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            return back()->with('error', 'Only draft entries can be posted.');
        }

        $entry->loadMissing('lines');
        $debits = (int) $entry->lines->sum('debit_kobo');
        $credits = (int) $entry->lines->sum('credit_kobo');

        if ($debits !== $credits || $debits <= 0) {
            return back()->with('error', 'Debits and credits must match before posting.');
        }

        try {
            $period = $setup->resolveOpenPeriod($entry->business_id, $entry->entry_date->toDateString());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $entry->update([
            'status' => JournalEntry::STATUS_POSTED,
            'fiscal_period_id' => $period->id,
            'posted_at' => now(),
            'posted_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Entry posted.');
    }

    public function destroy(Request $request, JournalEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            return back()->with('error', 'Only draft entries can be deleted. Use reversal for posted entries.');
        }

        DB::transaction(function () use ($entry) {
            $entry->lines()->delete();
            $entry->delete();
        });

        return redirect()->route('management.accounting.journal.index')
            ->with('success', 'Draft deleted.');
    }

    public function reverse(Request $request, JournalEntry $entry, LedgerPostingService $posting): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            return back()->with('error', 'Only posted entries can be reversed.');
        }

        try {
            $posting->reverseEntry($entry, 'Reversal of '.$entry->entry_number, $request->user()->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('management.accounting.journal.show', $entry)
            ->with('success', 'Reversing entry posted.');
    }

    private function authorizeEntry(Request $request, JournalEntry $entry): void
    {
        if ($entry->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
