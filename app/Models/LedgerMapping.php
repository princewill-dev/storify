<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerMapping extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'key', 'ledger_account_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
