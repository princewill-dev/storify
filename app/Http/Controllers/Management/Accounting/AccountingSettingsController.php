<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Services\Accounting\LedgerClosingService;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountingSettingsController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $request->user()->business_id;

        app(LedgerSetupService::class)->ensureForBusiness($businessId);

        $accounts = LedgerAccount::where('business_id', $businessId)->active()->orderBy('code')->get();
        $mappings = LedgerMapping::where('business_id', $businessId)->with('account')->get()->keyBy('key');

        $periods = FiscalPeriod::where('business_id', $businessId)
            ->orderByDesc('name')
            ->limit(24)
            ->get();

        $fiscalYears = FiscalYear::where('business_id', $businessId)
            ->orderByDesc('name')
            ->limit(6)
            ->get();

        $openingEntry = JournalEntry::where('business_id', $businessId)
            ->where('idempotency_key', 'like', 'opening:%')
            ->first();

        return view('management.accounting.settings.index', compact('accounts', 'mappings', 'periods', 'fiscalYears', 'openingEntry'));
    }

    public function closeYear(Request $request, int $year, LedgerClosingService $closing): RedirectResponse
    {
        try {
            $entry = $closing->closeYear($request->user()->business_id, $year, $request->user()->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $entry
            ? "Fiscal year {$year} closed — net result transferred to retained earnings."
            : "Fiscal year {$year} closed.");
    }

    public function updateMappings(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
        ]);

        foreach ($validated['mappings'] as $key => $accountId) {
            LedgerMapping::updateOrCreate(
                ['business_id' => $businessId, 'key' => $key],
                ['ledger_account_id' => $accountId]
            );
        }

        return back()->with('success', 'Account mappings updated.');
    }

    public function storeOpeningBalances(Request $request, LedgerPostingService $posting): RedirectResponse
    {
        $businessId = $request->user()->business_id;

        if (JournalEntry::where('business_id', $businessId)->where('idempotency_key', 'like', 'opening:%')->exists()) {
            return back()->with('error', 'Opening balances have already been posted.');
        }

        $validated = $request->validate([
            'as_of' => ['required', 'date'],
            'cash' => ['nullable', 'numeric'],
            'bank' => ['nullable', 'numeric'],
            'gateway_clearing' => ['nullable', 'numeric'],
            'accounts_receivable' => ['nullable', 'numeric'],
            'inventory' => ['nullable', 'numeric'],
            'fixed_assets' => ['nullable', 'numeric'],
            'accounts_payable' => ['nullable', 'numeric'],
            'tax_payable' => ['nullable', 'numeric'],
            'owner_equity' => ['nullable', 'numeric'],
        ]);

        $balances = [];
        foreach (['cash', 'bank', 'gateway_clearing', 'accounts_receivable', 'inventory', 'fixed_assets'] as $debitKey) {
            $amount = (float) ($validated[$debitKey] ?? 0);
            if ($amount != 0.0) {
                $balances[$debitKey] = (int) round($amount * 100);
            }
        }

        foreach (['accounts_payable', 'tax_payable', 'owner_equity'] as $creditKey) {
            $amount = (float) ($validated[$creditKey] ?? 0);
            if ($amount != 0.0) {
                $balances[$creditKey] = -abs((int) round($amount * 100));
            }
        }

        if (empty($balances)) {
            return back()->with('error', 'Enter at least one opening balance.');
        }

        try {
            $posting->postOpeningBalances($businessId, $balances, $validated['as_of'], $request->user()->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening balances posted.');
    }

    public function closePeriod(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $this->authorizePeriod($request, $period);

        if (! $period->isOpen()) {
            return back()->with('error', 'This period is already closed.');
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
        ]);

        return back()->with('success', "Period {$period->name} closed.");
    }

    public function reopenPeriod(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $this->authorizePeriod($request, $period);

        if ($period->isOpen()) {
            return back()->with('error', 'This period is already open.');
        }

        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return back()->with('success', "Period {$period->name} reopened.");
    }

    private function authorizePeriod(Request $request, FiscalPeriod $period): void
    {
        if ($period->business_id !== $request->user()->business_id) {
            abort(403);
        }
    }
}
