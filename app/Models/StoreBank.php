<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBusiness;

class StoreBank extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'store_id',
        'bank_name',
        'bank_code',
        'account_number',
        'account_name',
        'is_primary',
        'is_verified',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
    ];

    /**
     * Get the store that owns this bank account.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Mask the account number for display.
     */
    public function getMaskedAccountNumberAttribute(): string
    {
        $number = $this->account_number;
        if (strlen($number) <= 4) {
            return $number;
        }
        return substr($number, 0, 3) . str_repeat('*', strlen($number) - 5) . substr($number, -2);
    }
}
