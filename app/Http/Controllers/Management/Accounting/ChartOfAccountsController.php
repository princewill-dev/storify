<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChartOfAccountsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $accounts = LedgerAccount::query()
            ->where('business_id', $businessId)
            ->with('parent')
            ->orderBy('code')
            ->get();

        $balances = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->groupBy('journal_lines.ledger_account_id')
            ->selectRaw('journal_lines.ledger_account_id, SUM(journal_lines.debit_kobo) as debit, SUM(journal_lines.credit_kobo) as credit')
            ->get()
            ->keyBy('ledger_account_id');

        $grouped = $accounts->groupBy('type');

        return view('management.accounting.accounts.index', compact('accounts', 'balances', 'grouped'));
    }

    public function create(Request $request): View
    {
        $user = $request->user();

        $parents = LedgerAccount::query()
            ->where('business_id', $user->business_id)
            ->active()
            ->orderBy('code')
            ->get();

        return view('management.accounting.accounts.form', [
            'account' => new LedgerAccount,
            'parents' => $parents,
            'types' => LedgerAccount::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $this->validateAccount($request, $user->business_id);

        LedgerAccount::create([
            'business_id' => $user->business_id,
            ...$validated,
            'currency' => 'NGN',
            'is_system' => false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('management.accounting.accounts.index')
            ->with('success', 'Account created.');
    }

    public function edit(Request $request, LedgerAccount $account): View
    {
        $this->authorizeAccount($request, $account);

        $parents = LedgerAccount::query()
            ->where('business_id', $account->business_id)
            ->whereKeyNot($account->id)
            ->orderBy('code')
            ->get();

        return view('management.accounting.accounts.form', [
            'account' => $account,
            'parents' => $parents,
            'types' => LedgerAccount::TYPES,
        ]);
    }

    public function update(Request $request, LedgerAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        $validated = $this->validateAccount($request, $account->business_id, $account->id);

        $account->update($validated);

        return redirect()->route('management.accounting.accounts.index')
            ->with('success', 'Account updated.');
    }

    public function toggle(Request $request, LedgerAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);

        if ($account->is_system && $account->is_active) {
            return back()->with('error', 'System accounts cannot be deactivated.');
        }

        $account->update(['is_active' => ! $account->is_active]);

        return back()->with('success', $account->is_active ? 'Account activated.' : 'Account deactivated.');
    }

    private function validateAccount(Request $request, int $businessId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('ledger_accounts', 'code')
                    ->where(fn ($q) => $q->where('business_id', $businessId))
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LedgerAccount::TYPES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeAccount(Request $request, LedgerAccount $account): void
    {
        if ($account->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
