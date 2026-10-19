<?php

namespace App\Services\Payments\Custody;

use App\Models\Setting;
use App\Services\Payments\Connect\PlatformCommission;

/**
 * The platform-wide payout policy: a floor and a flat fee.
 *
 * Both are read from the singleton settings row at send time, deliberately
 * un-memoised — the same choice {@see PlatformCommission}
 * makes for its figures. An admin can change either while a worker is alive,
 * and the next attempt should act on the new policy rather than on a value the
 * process happened to load at boot.
 *
 * Read together in one query because the sweep asks once per business, and the
 * two figures are always used as a pair.
 */
final class PayoutPolicy
{
    /**
     * @return array{minimum_kobo: int, fee_kobo: int}
     */
    public static function current(): array
    {
        $row = Setting::query()->first(['minimum_payout_kobo', 'payout_processing_fee_kobo']);

        return [
            'minimum_kobo' => max(0, (int) ($row?->minimum_payout_kobo ?? 0)),
            'fee_kobo' => max(0, (int) ($row?->payout_processing_fee_kobo ?? 0)),
        ];
    }
}
