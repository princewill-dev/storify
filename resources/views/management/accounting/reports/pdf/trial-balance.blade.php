<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Trial Balance</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .muted { color: #64748b; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
    th { background: #f8fafc; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
    .right { text-align: right; }
    tfoot td { font-weight: bold; border-top: 2px solid #cbd5e1; }
    .balanced { color: #059669; }
    .unbalanced { color: #dc2626; }
</style>
</head>
<body>
    <h1>Trial Balance</h1>
    <p class="muted">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>

    <table>
        <thead>
            <tr>
                <th style="width:60px;">Code</th>
                <th>Account</th>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['lines'] as $line)
            <tr>
                <td>{{ $line['account']->code }}</td>
                <td>{{ $line['account']->name }}</td>
                <td class="right">{{ $line['debit'] > 0 ? number_format($line['debit'] / 100, 2) : '—' }}</td>
                <td class="right">{{ $line['credit'] > 0 ? number_format($line['credit'] / 100, 2) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="right">Totals</td>
                <td class="right">{{ number_format($report['total_debit'] / 100, 2) }}</td>
                <td class="right">{{ number_format($report['total_credit'] / 100, 2) }}</td>
            </tr>
            <tr>
                <td colspan="4" class="right {{ $report['total_debit'] === $report['total_credit'] ? 'balanced' : 'unbalanced' }}">
                    {{ $report['total_debit'] === $report['total_credit'] ? 'Balanced' : 'Out of balance' }}
                </td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
