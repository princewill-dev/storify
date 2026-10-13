<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something a business was told about its money.
 *
 * Written once per event and never updated except to mark it read or to record how
 * the email about it went. Both halves are the point: the in-app record is what a
 * business reads when it wants to know what happened, and the unique `dedupe_key`
 * is what stops the same event being written — and mailed — twice. See the
 * migration for why the row itself is the exactly-once guard rather than a flag
 * somewhere else.
 */
class BusinessNotification extends Model
{
    public const TYPE_WALLET_CREDITED = 'wallet.credited';

    public const TYPE_PAYOUT_PAID = 'payout.paid';

    public const TYPE_PAYOUT_FAILED = 'payout.failed';

    public const TYPE_PAYOUT_REVERSED = 'payout.reversed';

    public const TYPE_PAYOUT_BLOCKED = 'payout.blocked';

    public const LEVEL_INFO = 'info';

    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    protected $fillable = [
        'business_id', 'user_id', 'type', 'level', 'title', 'body',
        'subject_type', 'subject_id', 'amount_kobo', 'currency',
        'metadata', 'dedupe_key', 'read_at', 'emailed_at', 'mail_error',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'user_id' => 'integer',
        'subject_id' => 'integer',
        'amount_kobo' => 'integer',
        'metadata' => 'array',
        'read_at' => 'datetime',
        'emailed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The transaction or payout this is about.
     *
     * A morph rather than two relations, because an alert outlives its subject at
     * the edges: a notification about a purged payout is still a true record of
     * something a business was told, and a nullable integer is a better answer
     * there than a deletion.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Whether the mail reached the business. False while it is still unwritten. */
    public function emailed(): bool
    {
        return $this->emailed_at !== null;
    }

    public function unread(): bool
    {
        return $this->read_at === null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }
}
