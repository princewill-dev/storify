<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SettingsUpdated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public array $changes;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries;

    /**
     * Backoff time between attempts (seconds).
     */
    public function backoff(): array
    {
        $raw = env('QUEUE_BACKOFF', '10,30,60');
        // Accept CSV like "10,30,60" or an array
        if (is_array($raw)) {
            return array_map('intval', $raw);
        }
        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), fn ($v) => $v !== '');
        $nums = array_map(fn ($v) => (int) $v, $parts);
        return count($nums) ? $nums : [10, 30, 60];
    }

    public function __construct(array $changes)
    {
        $this->changes = $changes;
        $this->tries = (int) env('QUEUE_RETRY_COUNT', 3);
    }

    public function build(): self
    {
        return $this->subject('Settings Updated Notification')
            ->view('emails.settings-updated')
            ->with(['changes' => $this->changes]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $e): void
    {
        // Log to a dedicated channel if configured; falls back to default
        Log::channel('queue')->error('settings_update_email_failed', [
            'error' => $e->getMessage(),
            'mailable' => static::class,
        ]);
    }
}
