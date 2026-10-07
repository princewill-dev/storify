<?php

namespace App\Services\Management;

use App\Mail\SupportMessageReplyMail;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-33 — the management support reply workflow.
 *
 * The status/reply flip commits inside its DB::transaction exactly where the
 * controller ran it, and the ActivityLogger audit row follows it outside that
 * transaction — the same point in the sequence as before. (The admin side's
 * recorder contract keeps its row inside; this slice is not that contract.)
 *
 * The reply's customer email is queued after a fresh read with the store
 * relation the payload and the mail view read. The status flip is the durable
 * record and a transient mail failure must not lose the reply (legacy wrapped
 * the queue call the same way), so a failed queue call is logged rather than
 * surfaced.
 *
 * The audit entry goes to `activity_logs` (ActivityLogger) so it is
 * queryable, mirroring the legacy's Log::info intent without cloning the
 * legacy log key that AGENTS.md forbids. The HTTP shape — the 403 tenant
 * guard, the closed-conversation 422 and the success message — stays in the
 * controller, which refuses before this runs.
 */
final class SupportMessageService
{
    /**
     * The reply write: flip, audit, re-read, email — in that order.
     */
    public function reply(SupportMessage $message, string $reply, User $user): SupportMessage
    {
        DB::transaction(function () use ($message, $reply, $user) {
            $message->update([
                'reply' => $reply,
                'status' => 'replied',
                'replied_by_type' => 'business',
                'replied_by_id' => $user->id,
                'replied_at' => now(),
            ]);
        });

        ActivityLogger::log(
            'support_message_replied',
            'Business replied to a support message from '.$message->name,
            [
                'support_message_id' => $message->id,
                'store_id' => $message->store_id,
            ],
            $user->id,
        );

        $fresh = $message->fresh()->load('store');

        // The status flip above is the durable record; a transient mail failure
        // must not lose the reply (legacy wrapped the queue call the same way).
        try {
            Mail::to($fresh->email)->queue(new SupportMessageReplyMail($fresh));

            Log::info('support.message.reply_email_queued', [
                'message_id' => $fresh->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('support.message.reply_email_failed', [
                'message_id' => $fresh->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fresh;
    }
}
