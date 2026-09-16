<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use BelongsToBusiness;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'business_id', 'supplier_id', 'bill_number', 'issue_date', 'due_date',
        'subtotal_kobo', 'tax_kobo', 'total_kobo', 'amount_paid_kobo', 'status',
        'notes', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal_kobo' => 'integer',
        'tax_kobo' => 'integer',
        'total_kobo' => 'integer',
        'amount_paid_kobo' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function remainingBalanceKobo(): int
    {
        return max(0, (int) $this->total_kobo - (int) $this->amount_paid_kobo);
    }

    public function isFullyPaid(): bool
    {
        return (int) $this->amount_paid_kobo >= (int) $this->total_kobo;
    }
}
