<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'start_date', 'end_date', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
