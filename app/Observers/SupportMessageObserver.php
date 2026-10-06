<?php

namespace App\Observers;

use App\Mail\AdminNewSupportMessageMail;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-16 — platform-office notification for new storefront support messages.
 *
 * The storefront endpoint (`Api\V1\Storefront\SupportController@store`) emails
 * the *store* when a customer writes in, but the platform office — the team
 * that actually works the admin inbox — was never told (the audit's "add a
 * platform notification on new submission"). Hanging this off the model's
 * `created` event covers every creation path without editing the storefront
 * controller, which belongs to another workstream.
 *
 * WIRING (orchestrator — this file needs one registration line in a shared
 * file): models cannot auto-discover observers. Either
 *   1. add App\Providers\SupportInboxServiceProvider to bootstrap/providers.php
 *      (it boots `SupportMessage::observe(SupportMessageObserver::class)`), or
 *   2. add the same observe() call to AppServiceProvider::boot().
 *
 * Failures are swallowed and logged: a mail problem must never fail the public
 * storefront request that created the message.
 */
class SupportMessageObserver
{
    public function created(SupportMessage $message): void
    {
        $recipients = User::query()
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->where('status', 'active')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        // No platform office accounts → nobody to notify. Deliberately no
        // mail.from fallback: the store is already emailed by the caller, and
        // a fallback would spray the technical from-address with every
        // storefront submission.
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipients->all())->queue(new AdminNewSupportMessageMail($message->loadMissing('store')));
        } catch (\Throwable $e) {
            Log::error('api.admin.support_message_created_mail_failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
