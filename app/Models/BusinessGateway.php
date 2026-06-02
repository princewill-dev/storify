<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBusiness;

class BusinessGateway extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'gateway',
        'public_key',
        'secret_key',
        'webhook_id',
        'is_active',
        'is_verified',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'metadata' => 'json',
    ];

    protected $hidden = [
        'public_key',
        'secret_key',
    ];

    protected function publicKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? decrypt($value) : null,
            set: fn ($value) => encrypt($value),
        );
    }

    protected function secretKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? decrypt($value) : null,
            set: fn ($value) => encrypt($value),
        );
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function getMaskedPublicKeyAttribute(): string
    {
        $key = $this->public_key;
        if (!$key || strlen($key) < 12) {
            return '—';
        }
        return substr($key, 0, 8) . '****' . substr($key, -6);
    }

    public function getMaskedSecretKeyAttribute(): string
    {
        $key = $this->secret_key;
        if (!$key || strlen($key) < 12) {
            return '—';
        }
        return substr($key, 0, 8) . '****' . substr($key, -6);
    }
}
