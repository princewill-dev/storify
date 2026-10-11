<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected-account delivery, claimed by its provider event id.
 *
 * The provider's own guarantee is "at least once": a delivery is retried until
 * it is answered with a 2xx, and a response that was merely slow is retried
 * too. Two deliveries of one event must therefore do one thing between them,
 * and this row is how that is decided before any of the work starts.
 *
 * **Claim, then act, then release on failure.** {@see claim()} inserts the row
 * and reports whether it won; a second delivery of the same id loses and is
 * answered as a duplicate without touching anything. If the work then throws,
 * {@see release()} removes the claim so the provider's retry is processed
 * normally — the alternative, marking it before the work is known to have
 * succeeded, would swallow exactly the deliveries that most need retrying.
 */
class ConnectWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'event_id', 'event_type', 'account_id', 'status', 'note',
    ];

    /**
     * Take the claim on an event, or report that someone already has it.
     *
     * `insertOrIgnore` rather than a read followed by a write: two deliveries of
     * the same event can arrive at the same moment on separate workers, and the
     * unique index is the only thing that can arbitrate between them. A
     * check-then-insert would let both through.
     *
     * Returns null when the event has been seen before — including while an
     * earlier delivery is still in flight, which is the desired answer: the work
     * is happening.
     */
    public static function claim(string $provider, string $eventId, string $eventType, ?string $accountId): ?self
    {
        $now = now();

        $inserted = static::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'account_id' => $accountId,
            'status' => 'processing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        return static::query()->where('event_id', $eventId)->first();
    }

    /**
     * What was done with the delivery, once it worked.
     *
     * Written after the work rather than before it, so the row's existence
     * always means "claimed" and its `status` always means "and here is how it
     * ended". `processing` therefore only ever describes a delivery in flight.
     */
    public function settle(string $status, ?string $note = null): void
    {
        $this->forceFill([
            'status' => $status,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ])->save();
    }

    /**
     * Give the claim back, because the work failed and a retry should run.
     */
    public function release(): void
    {
        $this->delete();
    }

    /**
     * How many deliveries we have recorded for an account, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentForAccount(string $accountId, int $limit = 10): array
    {
        return static::query()
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['event_id', 'event_type', 'status', 'note', 'created_at'])
            ->map(fn (self $event): array => [
                'event_id' => $event->event_id,
                'type' => $event->event_type,
                'status' => $event->status,
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
