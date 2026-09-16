<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'email', 'phone', 'address', 'notes',
        'opening_balance_kobo', 'is_active',
    ];

    protected $casts = [
        'opening_balance_kobo' => 'integer',
        'is_active' => 'boolean',
    ];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
