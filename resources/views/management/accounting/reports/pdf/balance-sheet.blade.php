<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Balance Sheet</title>
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
    .balanced { color: #059669; }
    .unbalanced { color: #dc2626; }
</style>
</head>
<body>
    <h1>Balance Sheet</h1>
    <p class="muted">As of {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}</p>

    <h2>Assets</h2>
    <table>
        <tbody>
            @forelse($report['assets'] as $line)
            <tr><td>{{ $line['account']->code }} — {{ $line['account']->name }}</td><td class="right">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
            @empty
            <tr><td colspan="2" class="muted">No asset balances.</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td>Total Assets</td><td class="right">{{ number_format($report['total_assets'] / 100, 2) }}</td></tr></tfoot>
    </table>

    <h2>Liabilities</h2>
    <table>
        <tbody>
            @forelse($report['liabilities'] as $line)
            <tr><td>{{ $line['account']->code }} — {{ $line['account']->name }}</td><td class="right">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
            @empty
            <tr><td colspan="2" class="muted">No liabilities.</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td>Total Liabilities</td><td class="right">{{ number_format($report['total_liabilities'] / 100, 2) }}</td></tr></tfoot>
    </table>

    <h2>Equity</h2>
    <table>
        <tbody>
            @foreach($report['equity'] as $line)
            <tr><td>{{ $line['account']->code }} — {{ $line['account']->name }}</td><td class="right">{{ number_format($line['amount'] / 100, 2) }}</td></tr>
            @endforeach
            <tr><td>Current Period Earnings</td><td class="right">{{ number_format($report['net_profit'] / 100, 2) }}</td></tr>
        </tbody>
        <tfoot><tr><td>Total Equity</td><td class="right">{{ number_format($report['total_equity'] / 100, 2) }}</td></tr></tfoot>
    </table>

    <p class="{{ abs($report['total_assets'] - ($report['total_liabilities'] + $report['total_equity'])) < 100 ? 'balanced' : 'unbalanced' }}">
        {{ abs($report['total_assets'] - ($report['total_liabilities'] + $report['total_equity'])) < 100 ? 'Balanced' : 'Out of balance' }}
        — Liabilities + Equity: {{ number_format(($report['total_liabilities'] + $report['total_equity']) / 100, 2) }}
    </p>
</body>
</html>
