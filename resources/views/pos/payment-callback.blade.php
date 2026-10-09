<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment received</title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#020617;color:#e2e8f0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;">
    <div style="text-align:center;padding:32px;max-width:420px;">
        <div style="width:56px;height:56px;margin:0 auto 20px;border-radius:999px;background:#065f46;display:flex;align-items:center;justify-content:center;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6ee7b7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>

        <h1 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#f1f5f9;">Payment received</h1>
        <p style="margin:0;font-size:14px;line-height:1.6;color:#94a3b8;">
            This window closes itself. The till is confirming the payment and will
            finish the sale on its own.
        </p>
    </div>

    {{--
        The till polls the provider rather than waiting on this page, so closing
        is cosmetic — but a window that hangs open on a payment screen after the
        customer has paid reads as a failure, and a cashier will try to fix it.
    --}}
    <script>setTimeout(function () { window.close(); }, 800);</script>
</body>
</html>
