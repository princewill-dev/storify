<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS-24 — accounting report reads plus the legacy `?export=csv|pdf` branches.
 *
 * The shared route file registers these nine URIs against
 * Api\V1\Management\AccountingController; routes/api/v1/management/ws24-accounting-reports.php
 * re-registers the same URIs here (feature modules load last and win), so one
 * endpoint per report serves both the JSON the SPA already consumes and the
 * file an accountant downloads. The JSON branches mirror
 * AccountingController's payloads field-for-field — the export additions must
 * never change what a plain GET returns.
 *
 * CSV files reproduce the legacy headers, row order and number formatting
 * (2dp, no thousands separator, dot decimal) so they stay interchangeable with
 * accountants' templates. PDFs reproduce the legacy reports/pdf/* layouts,
 * ported to resources/views/pdf/accounting/*.
 */
class ReportsController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly LedgerReportService $reports) {}

    public function profitAndLoss(Request $request): JsonResponse|StreamedResponse|Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->profitAndLoss($this->user($request)->business_id, $from, $to);

        if ($request->input('export') === 'pdf') {
            $amount = fn (int $kobo): string => $this->amountGrouped($kobo);

            return Pdf::loadView('pdf.accounting.profit-and-loss', compact('report', 'from', 'to', 'amount'))
                ->download('profit-and-loss-'.now()->format('Y-m-d').'.pdf');
        }

        if ($request->input('export') === 'csv') {
            $rows = collect($report['income'])->map(fn ($line) => [
                'Income', $line['account']->code, $line['account']->name, $this->amount((int) $line['amount']),
            ])->merge(collect($report['expenses'])->map(fn ($line) => [
                'Expense', $line['account']->code, $line['account']->name, $this->amount((int) $line['amount']),
            ]));

            return $this->csv('profit-and-loss', ['Section', 'Code', 'Account', 'Amount'], $rows);
        }

        return $this->ok($report);
    }

    public function balanceSheet(Request $request): JsonResponse|StreamedResponse|Response
    {
        $asOf = $this->dateParam($request, 'as_of', now()->toDateString());
        $report = $this->reports->balanceSheet($this->user($request)->business_id, $asOf);

        if ($request->input('export') === 'pdf') {
            $amount = fn (int $kobo): string => $this->amountGrouped($kobo);

            return Pdf::loadView('pdf.accounting.balance-sheet', compact('report', 'asOf', 'amount'))
                ->download('balance-sheet-'.now()->format('Y-m-d').'.pdf');
        }

        if ($request->input('export') === 'csv') {
            $rows = collect($report['assets'])->map(fn ($line) => [
                'Asset', $line['account']->code, $line['account']->name, $this->amount((int) $line['amount']),
            ])->merge(collect($report['liabilities'])->map(fn ($line) => [
                'Liability', $line['account']->code, $line['account']->name, $this->amount((int) $line['amount']),
            ]))->merge(collect($report['equity'])->map(fn ($line) => [
                'Equity', $line['account']->code, $line['account']->name, $this->amount((int) $line['amount']),
            ]));

            return $this->csv('balance-sheet', ['Section', 'Code', 'Account', 'Amount'], $rows);
        }

        return $this->ok($report);
    }

    public function trialBalance(Request $request): JsonResponse|StreamedResponse|Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->trialBalance($this->user($request)->business_id, $from, $to);

        if ($request->input('export') === 'pdf') {
            $amount = fn (int $kobo): string => $this->amountGrouped($kobo);

            return Pdf::loadView('pdf.accounting.trial-balance', compact('report', 'from', 'to', 'amount'))
                ->download('trial-balance-'.now()->format('Y-m-d').'.pdf');
        }

        if ($request->input('export') === 'csv') {
            $rows = collect($report['lines'])->map(fn ($line) => [
                $line['account']->code,
                $line['account']->name,
                $this->amount((int) $line['debit']),
                $this->amount((int) $line['credit']),
            ]);

            return $this->csv('trial-balance', ['Code', 'Account', 'Debit', 'Credit'], $rows);
        }

        return $this->ok([
            'lines' => collect($report['lines'])->map(fn ($line) => [
                'code' => $line['account']->code,
                'name' => $line['account']->name,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ])->values()->all(),
            'total_debit' => $report['total_debit'],
            'total_credit' => $report['total_credit'],
        ]);
    }

    public function generalLedger(Request $request, LedgerAccount $account): JsonResponse|StreamedResponse
    {
        if ((int) $account->business_id !== (int) $this->user($request)->business_id) {
            abort(403);
        }

        [$from, $to] = $this->range($request);
        $report = $this->reports->generalLedger($this->user($request)->business_id, $account->id, $from, $to);

        if ($request->input('export') === 'csv') {
            $rows = collect($report['rows'])->map(fn ($row) => [
                $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'],
                $row['entry_number'],
                $row['memo'],
                $this->amount((int) $row['debit']),
                $this->amount((int) $row['credit']),
                $this->amount((int) $row['balance']),
            ]);

            return $this->csv('general-ledger', ['Date', 'Entry', 'Memo', 'Debit', 'Credit', 'Balance'], $rows);
        }

        return $this->ok([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'opening' => $report['opening'],
            'rows' => collect($report['rows'])->map(fn ($row) => [
                'date' => $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'],
                'entry_number' => $row['entry_number'],
                'memo' => $row['memo'],
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'balance' => $row['balance'],
            ])->values()->all(),
            'closing' => $report['closing'],
        ]);
    }

    public function arAging(Request $request): JsonResponse|StreamedResponse
    {
        $asOf = $this->dateParam($request, 'as_of', now()->toDateString());
        $report = $this->reports->arAging($this->user($request)->business_id, $asOf);

        if ($request->input('export') === 'csv') {
            return $this->agingCsv('ar-aging', $report);
        }

        return $this->ok($report);
    }

    public function apAging(Request $request): JsonResponse|StreamedResponse
    {
        $asOf = $this->dateParam($request, 'as_of', now()->toDateString());
        $report = $this->reports->apAging($this->user($request)->business_id, $asOf);

        if ($request->input('export') === 'csv') {
            return $this->agingCsv('ap-aging', $report);
        }

        return $this->ok($report);
    }

    public function vatSummary(Request $request): JsonResponse|StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->vatSummary($this->user($request)->business_id, $from, $to);

        if ($request->input('export') === 'csv') {
            return $this->csv('vat-summary', ['Description', 'Amount'], [
                ['Output VAT (sales)', $this->amount($report['output_tax'])],
                ['Input VAT (purchases)', $this->amount($report['input_tax'])],
                ['Net VAT payable', $this->amount($report['net_payable'])],
            ]);
        }

        return $this->ok($report);
    }

    public function expenseSummary(Request $request): JsonResponse|StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->expenseSummary($this->user($request)->business_id, $from, $to);

        if ($request->input('export') === 'csv') {
            $rows = $report['categories']->map(fn ($data, $name) => [
                $name, $data['count'], $this->amount((int) $data['total']),
            ])->values();

            return $this->csv('expense-summary', ['Category', 'Count', 'Total'], $rows);
        }

        return $this->ok($report);
    }

    public function integrity(Request $request): JsonResponse|StreamedResponse
    {
        $report = $this->reports->integrity($this->user($request)->business_id);

        if ($request->input('export') === 'csv') {
            $rows = collect($report['wallet_rows'])->map(fn ($row) => [
                $row['store']->name,
                $this->amount($row['wallet']),
                $this->amount($row['ledger']),
                $this->amount($row['difference']),
            ]);

            return $this->csv('ledger-integrity', ['Store', 'Wallet', 'Ledger', 'Difference'], $rows);
        }

        return $this->ok($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function agingCsv(string $filename, array $report): StreamedResponse
    {
        $rows = collect($report['buckets'])->flatMap(fn ($bucket) => collect($bucket['rows'])->map(fn ($row) => [
            $bucket['label'],
            $row['reference'],
            $row['contact'],
            $this->amount((int) $row['total']),
            $this->amount((int) $row['paid']),
            $this->amount((int) $row['outstanding']),
            $row['days_past_due'],
        ]));

        return $this->csv($filename, ['Bucket', 'Reference', 'Contact', 'Total', 'Paid', 'Outstanding', 'Days Past Due'], $rows);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        return [
            $this->dateParam($request, 'from', now()->startOfMonth()->toDateString()),
            $this->dateParam($request, 'to', now()->toDateString()),
        ];
    }

    /**
     * Legacy parsed these with bare Carbon::parse and blew up with a 500 on a
     * malformed date; a report filter typo is a 422, not a server fault.
     */
    private function dateParam(Request $request, string $key, string $default): string
    {
        $value = $request->input($key);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            abort(422, "The {$key} parameter must be a valid date.");
        }
    }

    /**
     * Streamed 2dp amount, format-identical to the legacy number_format path
     * but without ever putting kobo through a float. PHP_INT_MIN is not a
     * representable balance, so abs() is safe here.
     */
    private function amount(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $absolute = abs($kobo);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The legacy PDFs printed `number_format($kobo / 100, 2)` with its
     * default thousands separator, so the port groups the same way. CSV
     * templates keep the separator-free {@see amount()}; grouping here is
     * string maths only, so kobo still never enter a float.
     */
    private function amountGrouped(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $absolute = abs($kobo);
        $units = strrev(implode(',', str_split(strrev((string) intdiv($absolute, 100)), 3)));

        return $sign.$units.'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function csv(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');

            // Explicit escape keeps PHP 8.4's fputcsv deprecation away and
            // follows RFC 4180 (enclosure-only quoting) — the same convention
            // as the transactions export.
            fputcsv($handle, $headers, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }

            fclose($handle);
        }, $filename.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
