<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\Sms\TermiiSmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deliver one queued alert, once.
 *
 * `$tries = 1` is deliberate. A retry is only reachable after the first
 * attempt failed, and the failure it cannot distinguish is a timeout — where
 * Termii may already have accepted and sent the message. Charging the customer
 * twice for a notification is worse than an outbox row saying `failed`, which
 * is the record an operator can act on.
 *
 * The row is the payload (an integer id, not a serialized model), so a
 * deleted message is an early return rather than a job that cannot be found.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $smsMessageId) {}

    public function handle(TermiiSmsClient $client): void
    {
        $sms = SmsMessage::query()->find($this->smsMessageId);

        if ($sms === null || $sms->status === SmsMessage::STATUS_SENT) {
            return;
        }

        $result = $client->send($sms->phone, $sms->message);

        if ($result->ok) {
            $sms->forceFill([
                'status' => SmsMessage::STATUS_SENT,
                'provider_message_id' => $result->messageId,
                'error' => null,
                'sent_at' => now(),
            ])->save();

            return;
        }

        $sms->forceFill([
            'status' => SmsMessage::STATUS_FAILED,
            'error' => $result->error,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        Log::channel('queue')->error('queue_job_failed', [
            'job' => static::class,
            'sms_message_id' => $this->smsMessageId,
            'error' => $e->getMessage(),
        ]);
    }
}
