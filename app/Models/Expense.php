<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToBusiness;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'business_id', 'expense_category_id', 'ledger_account_id', 'supplier_id',
        'expense_date', 'amount_kobo', 'tax_kobo', 'total_kobo', 'currency',
        'payment_account_id', 'payment_method', 'reference', 'description',
        'receipt_path', 'status', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount_kobo' => 'integer',
        'tax_kobo' => 'integer',
        'total_kobo' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'payment_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function getAmountNairaAttribute(): float
    {
        return $this->amount_kobo / 100;
    }

    public function getTotalNairaAttribute(): float
    {
        return $this->total_kobo / 100;
    }
}
