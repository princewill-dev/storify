<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name', 'code', 'type', 'description', 'is_active', 'config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];

    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }

    public function businesses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_payment_method')
            ->withPivot('id', 'is_active', 'config')->withTimestamps();
    }

    public function stores(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_payment_method')
            ->withPivot('is_active')->withTimestamps();
    }

    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeGateway($query) { return $query->where('type', 'gateway'); }
    public function scopeTraditional($query) { return $query->where('type', 'traditional'); }
}
