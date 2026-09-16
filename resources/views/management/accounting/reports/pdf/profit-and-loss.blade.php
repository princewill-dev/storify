<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Profit &amp; Loss</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 12px; margin: 18px 0 4px; }
    .muted { color: #64748b; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
    th { background: #f8fafc; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
    .right { text-align: right; }
    tfoot td { font-weight: bold; border-top: 2px solid #cbd5e1; }
    .summary { width: 100%; margin-top: 14px; }
    .summary td { border: 1px solid #e2e8f0; padding: 10px; text-align: center; }
    .income { color: #059669; }
    .expense { color: #dc2626; }
</style>
</head>
<body>
    <h1>Profit &amp; Loss</h1>
    <p class="muted">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>

    <table class="summary">
        <tr>
            <td><strong>Total Income</strong><br><span class="income">{{ number_format($report['total_income'] / 100, 2) }}</span></td>
            <td><strong>Total Expenses</strong><br><span class="expense">{{ number_format($report['total_expenses'] / 100, 2) }}</span></td>
            <td><strong>Net Profit</strong><br><span class="{{ $report['net_profit'] >= 0 ? 'income' : 'expense' }}">{{ number_format($report['net_profit'] / 100, 2) }}</span></td>
        </tr>
    </table>

    <h2>Income</h2>
    <table>
        <tbody>
            @forelse($report['income'] as $line)
            <tr><td>{{ $line['account']->code }} — {{ $line['account']->name }}</td><td class="right">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
            @empty
            <tr><td colspan="2" class="muted">No income in this period.</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td>Total Income</td><td class="right">{{ number_format($report['total_income'] / 100, 2) }}</td></tr></tfoot>
    </table>

    <h2>Expenses</h2>
    <table>
        <tbody>
            @forelse($report['expenses'] as $line)
            <tr><td>{{ $line['account']->code }} — {{ $line['account']->name }}</td><td class="right">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
            @empty
            <tr><td colspan="2" class="muted">No expenses in this period.</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td>Total Expenses</td><td class="right">{{ number_format($report['total_expenses'] / 100, 2) }}</td></tr></tfoot>
    </table>
</body>
</html>
