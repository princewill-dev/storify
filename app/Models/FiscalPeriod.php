<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'fiscal_year_id', 'name', 'start_date', 'end_date',
        'status', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function containsDate(\DateTimeInterface|string $date): bool
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $date >= $this->start_date->format('Y-m-d')
            && $date <= $this->end_date->format('Y-m-d');
    }
}
