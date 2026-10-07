<?php

namespace App\Services\Admin;

use App\Mail\SupportMessageReplyMail;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-16 (admin console) — the support inbox write workflows.
 *
 * Every mutation pairs its table write with the ActivityRecorder row inside
 * one transaction (the recorder contract: a rejected audit row must take the
 * mutation down with it), keeping the exact order the controller used. The
 * HTTP shape — status codes, refusal copy, the envelope — stays in the
 * controller. The thread-state refusals (reply on a closed thread, a silent
 * re-reply, a double close/re-open) are checked there too; this layer assumes
 * the caller already refused them.
 *
 * The reply's customer email is queued after the transaction, never inside
 * it: the status flip is the durable record and a transient mail failure must
 * not lose the reply (legacy wrapped the queue call the same way). `reply()`
 * reports whether the email made it onto the queue so the response can be
 * honest about it — the toast must not claim the customer was emailed if
 * queueing failed.
 *
 * Thread ownership: `support_messages` has a single reply slot shared by the
 * business and the admin side. Whoever wants the slot back (or wants to
 * answer after a close) re-opens the conversation first; a re-reply
 * overwrites the column by design, and the previous text survives in the
 * audit row's old_values.
 */
final class SupportInboxService
{
    /**
     * @return array{message: SupportMessage, email_queued: bool}
     */
    public function reply(SupportMessage $message, string $reply, User $admin): array
    {
        DB::transaction(function () use ($message, $reply, $admin) {
            $old = $this->auditValues($message);

            $message->update([
                'reply' => $reply,
                'status' => 'replied',
                'replied_by_type' => 'admin',
                'replied_by_id' => $admin->id,
                'replied_at' => now(),
            ]);

            ActivityRecorder::record(
                action: 'support_message_replied',
                description: 'Admin replied to a support message from '.$message->name,
                subject: $message,
                old: $old,
                new: $this->auditValues($message),
                actor: $admin,
            );
        });

        // Re-read with the relations the payload and the mail view read — the
        // same sequence the controller ran: transaction, then fresh + load,
        // then the queue call.
        $fresh = $message->fresh()->load('store.business');

        return [
            'message' => $fresh,
            'email_queued' => $this->queueReplyMail($fresh, (int) $admin->id),
        ];
    }

    /**
     * Close a conversation without replying (or after replying). Revives the
     * schema's `closed` value, which no legacy code path ever set.
     */
    public function close(SupportMessage $message, User $admin): SupportMessage
    {
        DB::transaction(function () use ($message, $admin) {
            $old = $this->auditValues($message);
            $message->update(['status' => 'closed']);

            ActivityRecorder::record(
                action: 'support_message_closed',
                description: 'Admin closed the support conversation with '.$message->name,
                subject: $message,
                old: $old,
                new: $this->auditValues($message),
                actor: $admin,
            );
        });

        return $message->fresh()->load('store.business');
    }

    /**
     * Re-open a replied or closed conversation so a follow-up reply is
     * possible. The previous reply text is kept as context for the operator
     * (the next reply overwrites it; the audit row keeps the old text).
     */
    public function reopen(SupportMessage $message, User $admin): SupportMessage
    {
        DB::transaction(function () use ($message, $admin) {
            $old = $this->auditValues($message);
            $message->update(['status' => 'pending']);

            ActivityRecorder::record(
                action: 'support_message_reopened',
                description: 'Admin re-opened the support conversation with '.$message->name,
                subject: $message,
                old: $old,
                new: $this->auditValues($message),
                actor: $admin,
            );
        });

        return $message->fresh()->load('store.business');
    }

    /**
     * The row is hard-deleted (legacy had no soft delete); what it held is
     * captured first so the activity log still explains what left the inbox.
     */
    public function delete(SupportMessage $message, User $admin): void
    {
        $values = $this->auditValues($message);
        $messageId = $message->id;
        $metadata = [
            'support_message_id' => $messageId,
            'store_id' => $message->store_id,
            'customer_email' => $message->email,
            'message' => $message->message,
        ];

        DB::transaction(function () use ($message, $values, $metadata, $messageId, $admin) {
            $message->delete();

            ActivityRecorder::record(
                action: 'support_message_deleted',
                description: 'Support message #'.$messageId.' deleted',
                old: $values,
                metadata: $metadata,
                actor: $admin,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(SupportMessage $message): array
    {
        return [
            'status' => $message->status,
            'reply' => $message->reply,
            'replied_by_type' => $message->replied_by_type,
            'replied_by_id' => $message->replied_by_id,
            'replied_at' => $message->replied_at?->toISOString(),
        ];
    }

    /**
     * The status flip is the durable record; a transient mail failure must not
     * lose the reply (legacy wrapped the queue call the same way). Returns
     * whether the customer email made it onto the queue so the response can be
     * honest about it.
     */
    private function queueReplyMail(SupportMessage $message, int $adminId): bool
    {
        try {
            Mail::to($message->email)->queue(new SupportMessageReplyMail($message));

            Log::info('support.message.reply_email_queued', [
                'message_id' => $message->id,
                'admin_id' => $adminId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('support.message.reply_email_failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
