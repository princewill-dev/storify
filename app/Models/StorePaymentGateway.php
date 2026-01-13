<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class StorePaymentGateway extends Model
{
    protected $fillable = [
        'store_id',
        'gateway',
        'public_key',
        'secret_key',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Encrypt public key when setting
     */
    protected function publicKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? decrypt($value) : null,
            set: fn ($value) => $value ? encrypt($value) : null,
        );
    }

    /**
     * Encrypt secret key when setting
     */
    protected function secretKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? decrypt($value) : null,
            set: fn ($value) => $value ? encrypt($value) : null,
        );
    }

    /**
     * Get the store that owns this payment gateway.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get masked public key for display
     */
    public function getMaskedPublicKeyAttribute(): string
    {
        $key = $this->public_key;
        if (!$key || strlen($key) <= 8) {
            return $key;
        }
        return substr($key, 0, 4) . str_repeat('*', strlen($key) - 8) . substr($key, -4);
    }
}
