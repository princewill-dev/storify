<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillItem extends Model
{
    protected $fillable = [
        'bill_id', 'product_id', 'expense_account_id', 'description',
        'quantity', 'unit_cost_kobo', 'amount_kobo',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost_kobo' => 'integer',
        'amount_kobo' => 'integer',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'expense_account_id');
    }
}
