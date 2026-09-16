<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'store_bank_id', 'ledger_account_id', 'bank_statement_import_id',
        'start_date', 'end_date', 'statement_closing_balance_kobo',
        'cleared_balance_kobo', 'difference_kobo', 'status',
        'completed_by', 'completed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'statement_closing_balance_kobo' => 'integer',
        'cleared_balance_kobo' => 'integer',
        'difference_kobo' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function storeBank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }
}
