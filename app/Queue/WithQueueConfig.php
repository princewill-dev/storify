<?php

namespace App\Queue;

use Illuminate\Support\Facades\Log;
use Throwable;

trait WithQueueConfig
{
    /**
     * Number of times the job may be attempted.
     */
    public int $tries;

    /**
     * Initialize queue config from environment.
     */
    protected function initQueueConfig(): void
    {
        $this->tries = (int) env('QUEUE_RETRY_COUNT', 3);
    }

    /**
     * Backoff time between attempts (seconds).
     */
    public function backoff(): array
    {
        $raw = env('QUEUE_BACKOFF', '10,30,60');
        if (is_array($raw)) {
            return array_map('intval', $raw);
        }
        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), fn ($v) => $v !== '');
        $nums = array_map(fn ($v) => (int) $v, $parts);
        return count($nums) ? $nums : [10, 30, 60];
    }

    /**
     * Default failed handler writing to queue channel if available.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('queue')->error('queue_job_failed', [
            'job' => static::class,
            'error' => $e->getMessage(),
        ]);
    }
}
