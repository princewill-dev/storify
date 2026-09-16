<?php

namespace App\Http\Controllers\Management\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\LedgerReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingReportController extends Controller
{
    public function __construct(private readonly LedgerReportService $reports) {}

    public function index(Request $request): View
    {
        return view('management.accounting.reports.index');
    }

    public function trialBalance(Request $request): View|StreamedResponse|\Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->trialBalance($request->user()->business_id, $from, $to);

        if ($request->input('export') === 'pdf') {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('management.accounting.reports.pdf.trial-balance', compact('report', 'from', 'to'))
                ->download('trial-balance-'.now()->format('Y-m-d').'.pdf');
        }

        if ($request->input('export') === 'csv') {
            return $this->csv('trial-balance', ['Code', 'Account', 'Debit', 'Credit'], collect($report['lines'])->map(fn ($line) => [
                $line['account']->code,
                $line['account']->name,
                number_format($line['debit'] / 100, 2, '.', ''),
                number_format($line['credit'] / 100, 2, '.', ''),
            ]));
        }

        return view('management.accounting.reports.trial-balance', compact('report', 'from', 'to'));
    }

    public function profitAndLoss(Request $request): View|StreamedResponse|\Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->profitAndLoss($request->user()->business_id, $from, $to);

        if ($request->input('export') === 'pdf') {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('management.accounting.reports.pdf.profit-and-loss', compact('report', 'from', 'to'))
                ->download('profit-and-loss-'.now()->format('Y-m-d').'.pdf');
        }

        if ($request->input('export') === 'csv') {
            $rows = collect($report['income'])->map(fn ($line) => ['Income', $line['account']->code, $line['account']->name, number_format($line['amount'] / 100, 2, '.', '')])
                ->merge(collect($report['expenses'])->map(fn ($line) => ['Expense', $line['account']->code, $line['account']->name, number_format($line['amount'] / 100, 2, '.', '')]));

            return $this->csv('profit-and-loss', ['Section', 'Code', 'Account', 'Amount'], $rows);
        }

        return view('management.accounting.reports.profit-and-loss', compact('report', 'from', 'to'));
    }

    public function balanceSheet(Request $request): View|StreamedResponse|\Symfony\Component\HttpFoundation\Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();
        $report = $this->reports->balanceSheet($request->user()->business_id, $asOf);

        if ($request->input('export') === 'pdf') {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('management.accounting.reports.pdf.balance-sheet', compact('report', 'asOf'))
                ->download('balance-sheet-'.now()->format('Y-m-d').'.pdf');
        }

        if ($request->input('export') === 'csv') {
            $rows = collect($report['assets'])->map(fn ($line) => ['Asset', $line['account']->code, $line['account']->name, number_format($line['amount'] / 100, 2, '.', '')])
                ->merge(collect($report['liabilities'])->map(fn ($line) => ['Liability', $line['account']->code, $line['account']->name, number_format($line['amount'] / 100, 2, '.', '')]))
                ->merge(collect($report['equity'])->map(fn ($line) => ['Equity', $line['account']->code, $line['account']->name, number_format($line['amount'] / 100, 2, '.', '')]));

            return $this->csv('balance-sheet', ['Section', 'Code', 'Account', 'Amount'], $rows);
        }

        return view('management.accounting.reports.balance-sheet', compact('report', 'asOf'));
    }

    public function generalLedger(Request $request): View|StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $businessId = $request->user()->business_id;

        $accounts = \App\Models\LedgerAccount::where('business_id', $businessId)->orderBy('code')->get();
        $accountId = $request->integer('account') ?: ($accounts->first()?->id ?? 0);

        $report = $accountId ? $this->reports->generalLedger($businessId, $accountId, $from, $to) : null;

        if ($request->input('export') === 'csv' && $report) {
            $rows = collect($report['rows'])->map(fn ($row) => [
                $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'],
                $row['entry_number'],
                $row['memo'],
                number_format($row['debit'] / 100, 2, '.', ''),
                number_format($row['credit'] / 100, 2, '.', ''),
                number_format($row['balance'] / 100, 2, '.', ''),
            ]);

            return $this->csv('general-ledger', ['Date', 'Entry', 'Memo', 'Debit', 'Credit', 'Balance'], $rows);
        }

        return view('management.accounting.reports.general-ledger', compact('report', 'accounts', 'accountId', 'from', 'to'));
    }

    public function arAging(Request $request): View|StreamedResponse
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();
        $report = $this->reports->arAging($request->user()->business_id, $asOf);

        if ($request->input('export') === 'csv') {
            return $this->agingCsv('ar-aging', $report);
        }

        return view('management.accounting.reports.ar-aging', compact('report', 'asOf'));
    }

    public function apAging(Request $request): View|StreamedResponse
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();
        $report = $this->reports->apAging($request->user()->business_id, $asOf);

        if ($request->input('export') === 'csv') {
            return $this->agingCsv('ap-aging', $report);
        }

        return view('management.accounting.reports.ap-aging', compact('report', 'asOf'));
    }

    public function vatSummary(Request $request): View|StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->vatSummary($request->user()->business_id, $from, $to);

        if ($request->input('export') === 'csv') {
            return $this->csv('vat-summary', ['Description', 'Amount'], [
                ['Output VAT (sales)', number_format($report['output_tax'] / 100, 2, '.', '')],
                ['Input VAT (purchases)', number_format($report['input_tax'] / 100, 2, '.', '')],
                ['Net VAT payable', number_format($report['net_payable'] / 100, 2, '.', '')],
            ]);
        }

        return view('management.accounting.reports.vat-summary', compact('report', 'from', 'to'));
    }

    public function expenseSummary(Request $request): View|StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->expenseSummary($request->user()->business_id, $from, $to);

        if ($request->input('export') === 'csv') {
            $rows = $report['categories']->map(fn ($data, $name) => [$name, $data['count'], number_format($data['total'] / 100, 2, '.', '')])->values();

            return $this->csv('expense-summary', ['Category', 'Count', 'Total'], $rows);
        }

        return view('management.accounting.reports.expense-summary', compact('report', 'from', 'to'));
    }

    public function integrity(Request $request): View|StreamedResponse
    {
        $report = $this->reports->integrity($request->user()->business_id);

        if ($request->input('export') === 'csv') {
            $rows = collect($report['wallet_rows'])->map(fn ($row) => [
                $row['store']->name,
                number_format($row['wallet'] / 100, 2, '.', ''),
                number_format($row['ledger'] / 100, 2, '.', ''),
                number_format($row['difference'] / 100, 2, '.', ''),
            ]);

            return $this->csv('ledger-integrity', ['Store', 'Wallet', 'Ledger', 'Difference'], $rows);
        }

        return view('management.accounting.reports.integrity', compact('report'));
    }

    private function agingCsv(string $filename, array $report): StreamedResponse
    {
        $rows = collect($report['buckets'])->flatMap(fn ($bucket) => collect($bucket['rows'])->map(fn ($row) => [
            $bucket['label'],
            $row['reference'],
            $row['contact'],
            number_format($row['total'] / 100, 2, '.', ''),
            number_format($row['paid'] / 100, 2, '.', ''),
            number_format($row['outstanding'] / 100, 2, '.', ''),
            $row['days_past_due'],
        ]));

        return $this->csv($filename, ['Bucket', 'Reference', 'Contact', 'Total', 'Paid', 'Outstanding', 'Days Past Due'], $rows);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        return [$from, $to];
    }

    private function csv(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
