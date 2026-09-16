<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    use BelongsToBusiness;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

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
                $entry->entry_number = 'JE-'.strtoupper(\Illuminate\Support\Str::random(10));
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
