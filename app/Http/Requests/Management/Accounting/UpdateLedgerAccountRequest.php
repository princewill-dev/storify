<?php

namespace App\Http\Requests\Management\Accounting;

/**
 * WS-23 — `PUT /api/v1/management/accounting/accounts/{account}` payload.
 *
 * The code unique check ignores the bound account, so saving an account's own
 * code back is not a violation. The system-account deactivation guard
 * deliberately stays in the controller: it must keep its place in the write
 * sequence, after validation and before the parent-tree check.
 */
final class UpdateLedgerAccountRequest extends LedgerAccountWriteRequest
{
    protected function forUpdate(): bool
    {
        return true;
    }
}
