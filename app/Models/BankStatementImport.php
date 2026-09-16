<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'store_bank_id', 'ledger_account_id', 'file_path',
        'statement_date', 'opening_balance_kobo', 'closing_balance_kobo',
        'status', 'imported_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'opening_balance_kobo' => 'integer',
        'closing_balance_kobo' => 'integer',
    ];

    public function storeBank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
