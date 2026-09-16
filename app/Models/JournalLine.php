<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'ledger_account_id', 'store_id', 'description',
        'debit_kobo', 'credit_kobo', 'currency',
        'contact_type', 'contact_id', 'tax_kobo',
    ];

    protected $casts = [
        'debit_kobo' => 'integer',
        'credit_kobo' => 'integer',
        'tax_kobo' => 'integer',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
