<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BelongsToBusiness;

class Coupon extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'code', 'discount_type', 'discount_value',
        'max_uses', 'uses_count', 'is_active', 'expires_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_uses' => 'integer',
        'uses_count' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_uses && $this->uses_count >= $this->max_uses) return false;
        return true;
    }

    public function calculateDiscount(float $amount): float
    {
        if ($this->discount_type === 'percentage') {
            return round($amount * ($this->discount_value / 100), 2);
        }
        return min((float) $this->discount_value, $amount);
    }

    public function incrementUsage(): void
    {
        $this->increment('uses_count');
    }
}
