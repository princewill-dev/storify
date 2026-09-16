<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPayment extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'bill_id', 'supplier_id', 'payment_date', 'amount_kobo',
        'payment_account_id', 'method', 'reference', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_kobo' => 'integer',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'payment_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
