<?php

namespace App\Services\Storefront;

use App\Mail\AdminNewSupportMessageMail;
use App\Mail\SupportMessageReceivedMail;
use App\Models\Store;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The storefront support form's record-and-notify workflow
 * (`POST /api/v1/storefront/{store}/support`).
 *
 * The whole original sequence moves here in the order it ran: insert the row
 * with status `pending`, log the creation, queue the customer's receipt, then
 * queue the store's admin notification. The admin recipient is the store's
 * `support_email`, falling back to the configured from-address only when it
 * is null and skipped entirely when both are empty — the exact `??` plus
 * truthy check the controller used. (Creating the row also fires the
 * SupportMessageObserver, which notifies the platform office; that hook is
 * model-level and unchanged.)
 *
 * Failures are swallowed per queue call, exactly as before: the row is the
 * durable record, so a transient mail failure is logged under the original
 * keys instead of failing the public request. The HTTP shape — the 200, the
 * envelope and the exact success message — stays in the controller.
 *
 * The insert is a single Eloquent call and the workflow reads no other table,
 * so no repository is warranted here.
 */
final class SupportMessageService
{
    /**
     * @param  array<string, mixed>  $data  the validated support payload
     */
    public function record(Store $store, array $data): SupportMessage
    {
        $supportMessage = SupportMessage::create([
            'store_id' => $store->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'],
            'status' => 'pending',
        ]);

        Log::info('api.store.support_message.created', [
            'message_id' => $supportMessage->id,
            'store_id' => $store->id,
        ]);

        try {
            Mail::to($data['email'])->queue(new SupportMessageReceivedMail($supportMessage));
        } catch (\Exception $e) {
            Log::error('api.store.support_message.customer_email_failed', ['error' => $e->getMessage()]);
        }

        try {
            $adminEmail = $store->support_email ?? config('mail.from.address');
            if ($adminEmail) {
                Mail::to($adminEmail)->queue(new AdminNewSupportMessageMail($supportMessage));
            }
        } catch (\Exception $e) {
            Log::error('api.store.support_message.admin_email_failed', ['error' => $e->getMessage()]);
        }

        return $supportMessage;
    }
}
