<?php

namespace App\Http\Requests\Management\Accounting;

/**
 * WS-23 — `POST /api/v1/management/accounting/accounts` payload.
 */
final class StoreLedgerAccountRequest extends LedgerAccountWriteRequest
{
    protected function forUpdate(): bool
    {
        return false;
    }
}
