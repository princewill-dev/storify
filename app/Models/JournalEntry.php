<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class JournalEntry extends Model
{
    use BelongsToBusiness;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    /**
     * The statuses whose lines are part of the books. `draft` is the only one that
     * is not.
     *
     * **Void is in the books, and must be counted.** Voiding an entry here is not
     * a deletion: `LedgerPostingService::reverseEntry()` marks the original void
     * *and* posts a mirror of it, so the pair cancels. A balance query that reads
     * `status = posted` therefore drops the original's half of the pair while
     * still counting the reversal — the undoing is applied twice, and the account
     * lands at twice the intended movement. On a refunded own-key sale that is a
     * store wallet compared against a clearing balance that is short by the whole
     * amount of the sale, which reads as a catastrophic gap and is only a counting
     * error. Draft entries are the only ones genuinely absent from the books.
     */
    public const IN_BOOKS = [self::STATUS_POSTED, self::STATUS_VOID];

    protected $fillable = [
        'business_id', 'entry_number', 'entry_date', 'memo', 'reference',
        'source_type', 'source_id', 'status', 'fiscal_period_id',
        'idempotency_key', 'posted_at', 'posted_by',
        'voided_at', 'voided_by', 'reversal_of_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (JournalEntry $entry) {
            if (empty($entry->entry_number)) {
                $entry->entry_number = 'JE-'.strtoupper(Str::random(10));
            }
            if (empty($entry->entry_date)) {
                $entry->entry_date = now()->toDateString();
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function totalDebits(): int
    {
        return (int) $this->lines()->sum('debit_kobo');
    }

    public function totalCredits(): int
    {
        return (int) $this->lines()->sum('credit_kobo');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }
}
