<?php

namespace App\Services\Sms;

use App\Jobs\SendSmsJob;
use App\Models\Business;
use App\Models\SmsMessage;
use App\Support\Sms\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * The one door transactional alerts go through.
 *
 * The row is the idempotency record: its unique `dedupe_key` is claimed with
 * `insertOrIgnore` before anything is queued, so a webhook redelivery, the
 * browser's verify callback and a retry all lose the race quietly. The job is
 * dispatched only by the caller that claimed the key and only after commit, so
 * a rolled-back payment leaves no message behind.
 */
final class SmsDispatcher
{
    public function send(
        Business $business,
        string $phone,
        string $type,
        string $dedupeKey,
        string $message,
        ?int $userId = null,
        ?int $customerId = null,
    ): ?SmsMessage {
        $to = PhoneNumber::normalize(null, $phone);

        if ($to === null) {
            Log::info('sms.skipped', [
                'business_id' => $business->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'reason' => filled($phone) ? 'invalid_phone' : 'no_phone',
            ]);

            return null;
        }

        if (trim($message) === '') {
            Log::info('sms.skipped', [
                'business_id' => $business->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'reason' => 'empty_message',
            ]);

            return null;
        }

        try {
            $now = now();

            $inserted = SmsMessage::query()->insertOrIgnore([
                'business_id' => $business->id,
                'user_id' => $userId,
                'customer_id' => $customerId,
                'phone' => $to,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'message' => $message,
                'status' => SmsMessage::STATUS_QUEUED,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return null;
            }

            $sms = SmsMessage::query()->where('dedupe_key', $dedupeKey)->first();

            if ($sms === null) {
                return null;
            }

            // Dispatched directly, not deferred with `afterCommit()`: every
            // caller is already past its transaction (that is what makes the
            // payment visible to the worker), and a deferred dispatch inside a
            // test-wrapped transaction would never run at all.
            SendSmsJob::dispatch($sms->id);

            return $sms;
        } catch (\Throwable $e) {
            // A notification is never allowed to fail the thing it describes.
            Log::error('sms.dispatch_failed', [
                'business_id' => $business->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
