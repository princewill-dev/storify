<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'identifier',
        'code',
        'type',
        'expires_at',
        'is_verified',
        'verified_at',
        'ip_address',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    /**
     * Check if OTP is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    /**
     * Check if OTP is valid (not expired and not verified).
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->is_verified;
    }
}
