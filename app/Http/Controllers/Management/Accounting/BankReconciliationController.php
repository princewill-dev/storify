<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\StoreBank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BankReconciliationController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        $imports = BankStatementImport::query()
            ->where('business_id', $businessId)
            ->with(['storeBank', 'ledgerAccount'])
            ->withCount(['lines', 'lines as unmatched_count' => fn ($q) => $q->where('status', 'unmatched')])
            ->orderByDesc('id')
            ->paginate(15);

        $reconciliations = BankReconciliation::query()
            ->where('business_id', $businessId)
            ->with(['storeBank', 'ledgerAccount'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $bankAccounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->active()
            ->whereIn('subtype', ['bank', 'cash', 'gateway_clearing'])
            ->orderBy('code')
            ->get();

        $storeBanks = StoreBank::where('business_id', $businessId)->orderBy('bank_name')->get();

        return view('management.accounting.reconciliation.index', compact('imports', 'reconciliations', 'bankAccounts', 'storeBanks'));
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;

        $validated = $request->validate([
            'ledger_account_id' => ['required', 'integer'],
            'store_bank_id' => ['nullable', 'integer'],
            'statement_date' => ['nullable', 'date'],
            'opening_balance' => ['nullable', 'numeric'],
            'closing_balance' => ['nullable', 'numeric'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $account = LedgerAccount::where('business_id', $businessId)->findOrFail($validated['ledger_account_id']);

        $path = $request->file('file')->store('bank-statements', 'public');
        $rows = $this->parseCsv($request->file('file')->getRealPath());

        if (empty($rows)) {
            return back()->with('error', 'No valid rows found. Expected columns: date, description, reference, amount.');
        }

        $import = DB::transaction(function () use ($request, $validated, $businessId, $account, $path, $rows) {
            $import = BankStatementImport::create([
                'business_id' => $businessId,
                'store_bank_id' => $validated['store_bank_id'] ?? null,
                'ledger_account_id' => $account->id,
                'file_path' => $path,
                'statement_date' => $validated['statement_date'] ?? null,
                'opening_balance_kobo' => isset($validated['opening_balance']) ? (int) round($validated['opening_balance'] * 100) : null,
                'closing_balance_kobo' => isset($validated['closing_balance']) ? (int) round($validated['closing_balance'] * 100) : null,
                'status' => 'imported',
                'imported_by' => $request->user()->id,
            ]);

            foreach ($rows as $row) {
                BankStatementLine::create([
                    'bank_statement_import_id' => $import->id,
                    'business_id' => $businessId,
                    'transaction_date' => $row['date'],
                    'description' => $row['description'],
                    'reference' => $row['reference'],
                    'amount_kobo' => $row['amount_kobo'],
                    'status' => 'unmatched',
                ]);
            }

            return $import;
        });

        return redirect()->route('management.accounting.reconciliation.show', $import)
            ->with('success', count($rows).' statement line(s) imported.');
    }

    public function show(Request $request, BankStatementImport $import): View
    {
        $this->authorizeImport($request, $import);

        $import->load(['storeBank', 'ledgerAccount', 'lines' => fn ($q) => $q->orderBy('transaction_date')->orderBy('id')]);

        $ledgerClosing = (int) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $import->business_id)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $import->ledger_account_id)
            ->when($import->statement_date, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $import->statement_date))
            ->selectRaw('COALESCE(SUM(debit_kobo), 0) - COALESCE(SUM(credit_kobo), 0) as balance')
            ->value('balance');

        $unmatchedCount = $import->lines->where('status', 'unmatched')->count();
        $matchedTotal = (int) $import->lines->where('status', 'matched')->sum('amount_kobo');

        $matchedIds = BankStatementLine::query()
            ->where('business_id', $import->business_id)
            ->whereNotNull('matched_journal_line_id')
            ->pluck('matched_journal_line_id')
            ->all();

        $candidates = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $import->business_id)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $import->ledger_account_id)
            ->whereNotIn('journal_lines.id', $matchedIds)
            ->orderByDesc('journal_entries.entry_date')
            ->limit(100)
            ->get([
                'journal_lines.id',
                'journal_lines.debit_kobo',
                'journal_lines.credit_kobo',
                'journal_lines.description',
                'journal_entries.entry_date',
                'journal_entries.entry_number',
            ]);

        return view('management.accounting.reconciliation.show', compact(
            'import',
            'ledgerClosing',
            'unmatchedCount',
            'matchedTotal',
            'candidates'
        ));
    }

    public function autoMatch(Request $request, BankStatementImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $import);

        $matched = 0;
        $usedJournalLineIds = BankStatementLine::query()
            ->where('business_id', $import->business_id)
            ->whereNotNull('matched_journal_line_id')
            ->pluck('matched_journal_line_id')
            ->all();

        $lines = $import->lines()->where('status', 'unmatched')->orderBy('transaction_date')->get();

        foreach ($lines as $line) {
            $date = Carbon::parse($line->transaction_date);

            $candidates = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.business_id', $import->business_id)
                ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
                ->where('journal_lines.ledger_account_id', $import->ledger_account_id)
                ->whereDate('journal_entries.entry_date', '>=', $date->copy()->subDays(7)->toDateString())
                ->whereDate('journal_entries.entry_date', '<=', $date->copy()->addDays(7)->toDateString())
                ->when($line->amount_kobo >= 0,
                    fn ($q) => $q->where('journal_lines.debit_kobo', $line->amount_kobo),
                    fn ($q) => $q->where('journal_lines.credit_kobo', abs($line->amount_kobo)))
                ->whereNotIn('journal_lines.id', $usedJournalLineIds)
                ->orderByRaw('ABS(DATEDIFF(journal_entries.entry_date, ?))', [$date->toDateString()])
                ->select('journal_lines.id as journal_line_id')
                ->first();

            if (! $candidates) {
                continue;
            }

            $usedJournalLineIds[] = $candidates->journal_line_id;
            $line->update([
                'status' => 'matched',
                'matched_journal_line_id' => $candidates->journal_line_id,
            ]);
            $matched++;
        }

        return back()->with('success', "{$matched} line(s) auto-matched.");
    }

    public function match(Request $request, BankStatementLine $line): RedirectResponse
    {
        $this->authorizeLine($request, $line);

        $validated = $request->validate([
            'journal_line_id' => ['required', 'integer', 'exists:journal_lines,id'],
        ]);

        $journalLine = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $line->business_id)
            ->where('journal_lines.id', $validated['journal_line_id'])
            ->select('journal_lines.id')
            ->first();

        if (! $journalLine) {
            return back()->with('error', 'That ledger entry does not belong to this business.');
        }

        $line->update([
            'status' => 'matched',
            'matched_journal_line_id' => $journalLine->id,
        ]);

        return back()->with('success', 'Line matched.');
    }

    public function unmatch(Request $request, BankStatementLine $line): RedirectResponse
    {
        $this->authorizeLine($request, $line);

        $line->update([
            'status' => 'unmatched',
            'matched_journal_line_id' => null,
        ]);

        return back()->with('success', 'Match removed.');
    }

    public function ignore(Request $request, BankStatementLine $line): RedirectResponse
    {
        $this->authorizeLine($request, $line);

        $line->update(['status' => 'ignored', 'matched_journal_line_id' => null]);

        return back()->with('success', 'Line ignored.');
    }

    public function complete(Request $request, BankStatementImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $import);

        $validated = $request->validate([
            'statement_closing_balance' => ['required', 'numeric'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $statementClosingKobo = (int) round($validated['statement_closing_balance'] * 100);

        $ledgerClosing = (int) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $import->business_id)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $import->ledger_account_id)
            ->whereDate('journal_entries.entry_date', '<=', $validated['end_date'])
            ->selectRaw('COALESCE(SUM(debit_kobo), 0) - COALESCE(SUM(credit_kobo), 0) as balance')
            ->value('balance');

        BankReconciliation::create([
            'business_id' => $import->business_id,
            'store_bank_id' => $import->store_bank_id,
            'ledger_account_id' => $import->ledger_account_id,
            'bank_statement_import_id' => $import->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'statement_closing_balance_kobo' => $statementClosingKobo,
            'cleared_balance_kobo' => $ledgerClosing,
            'difference_kobo' => $statementClosingKobo - $ledgerClosing,
            'status' => 'completed',
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
        ]);

        $import->update(['status' => 'reconciled']);

        return redirect()->route('management.accounting.reconciliation.index')
            ->with('success', 'Reconciliation saved.');
    }

    /**
     * @return array<int, array{date: string, description: ?string, reference: ?string, amount_kobo: int}>
     */
    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $headers = null;

        while (($data = fgetcsv($handle)) !== false) {
            if (count(array_filter($data, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            if ($headers === null) {
                $normalised = array_map(fn ($v) => strtolower(trim((string) $v)), $data);

                if (in_array('date', $normalised, true) || in_array('amount', $normalised, true)) {
                    $headers = $normalised;
                    continue;
                }

                $headers = ['date', 'description', 'reference', 'amount'];
            }

            $row = array_combine(
                array_slice($headers, 0, count($data)),
                array_slice($data, 0, count($headers))
            ) ?: [];

            $date = $row['date'] ?? $data[0] ?? null;
            $amount = $row['amount'] ?? $data[count($data) - 1] ?? null;

            if (! $date || $amount === null || ! is_numeric(str_replace([',', '₦', ' '], '', (string) $amount))) {
                continue;
            }

            try {
                $parsedDate = Carbon::parse($date)->toDateString();
            } catch (\Throwable $e) {
                continue;
            }

            $amountValue = (float) str_replace([',', '₦', ' '], '', (string) $amount);

            $rows[] = [
                'date' => $parsedDate,
                'description' => $row['description'] ?? null,
                'reference' => $row['reference'] ?? null,
                'amount_kobo' => (int) round($amountValue * 100),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function authorizeImport(Request $request, BankStatementImport $import): void
    {
        if ($import->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }

    private function authorizeLine(Request $request, BankStatementLine $line): void
    {
        if ($line->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
