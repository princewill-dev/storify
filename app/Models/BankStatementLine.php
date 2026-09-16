<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'bank_statement_import_id', 'business_id', 'transaction_date',
        'description', 'reference', 'amount_kobo',
        'matched_journal_line_id', 'matched_transaction_id', 'status',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount_kobo' => 'integer',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'matched_journal_line_id');
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }
}
