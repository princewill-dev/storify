<?php

namespace App\Repositories\Management;

use App\Models\StoreBank;

/**
 * WS-11 — the store-side-only read the payment-method modal needs.
 *
 * The rest of this surface's data access is shared with the payment-settings
 * screens and already lives in PaymentSettingsRepository: the gateway and bank
 * listings, the bank_transfer method lookup, and the store_payment_method /
 * store_bank pivot writes. This repository adds only what that one does not
 * own rather than restating it — a second definition of the same query is how
 * the two surfaces would drift apart.
 *
 * Query building only: no abort() calls and no transaction boundaries; the
 * controller owns the store guard and its 404 refusals.
 */
final class StorePaymentMethodRepository
{
    /**
     * A bank account is reachable only through the business that owns it —
     * the legacy store-bank routes only checked the store, never the bank's
     * owner. A miss is reported by the controller as 404, not 403: a foreign
     * business's account id must not be distinguishable from a nonexistent
     * one.
     */
    public function findBankForBusiness(int $businessId, int $bankId): ?StoreBank
    {
        return StoreBank::where('business_id', $businessId)->find($bankId);
    }
}
