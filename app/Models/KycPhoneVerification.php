<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone the owner has proved they control, scoped to the business whose KYC
 * it belongs to. One row per user + number, refreshed on every successful
 * verification.
 */
class KycPhoneVerification extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'user_id',
        'business_id',
        'phone',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
