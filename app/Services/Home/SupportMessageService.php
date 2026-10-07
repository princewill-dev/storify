<?php

namespace App\Services\Home;

use App\Mail\SupportMessageAdmin;
use App\Mail\SupportMessageReceipt;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The marketing site contact form's mail workflow
 * (`POST /api/v1/home/support`).
 *
 * The whole original sequence moves here in the order it ran: resolve the
 * admin recipient from the singleton settings row (falling back to the
 * configured from-address), send the admin notification — or log the
 * missing-recipient warning with the message as its context — then the
 * customer receipt, then the success log. Both mailables implement
 * ShouldQueue, so `send()` enqueues them, exactly as the controller called
 * it. The recipient lookup is a single singleton read, so it stays out of a
 * repository.
 *
 * Failures are deliberately NOT swallowed here (unlike the admin inbox's
 * post-commit queue call): a support message that never sent is the whole
 * request failing, so the controller maps any exception — including the
 * settings read — to the same logged 500 it has always returned. The endpoint
 * is unauthenticated, so there is no guard that must run ahead of this
 * workflow.
 */
final class SupportMessageService
{
    /**
     * @param  array<string, mixed>  $data  the validated support payload
     */
    public function send(array $data): void
    {
        $adminEmail = Setting::query()->first()?->support_email ?? config('mail.from.address');

        if ($adminEmail) {
            Mail::to($adminEmail)->send(new SupportMessageAdmin($data));
        } else {
            Log::warning('Support email not configured. Message logged but not sent to admin.', $data);
        }

        Mail::to($data['email'])->send(new SupportMessageReceipt($data));

        Log::info('Home support message sent', ['email' => $data['email'], 'subject' => $data['subject']]);
    }
}
