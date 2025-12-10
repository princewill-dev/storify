<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LiveFirstApplication extends Model
{
    protected $fillable = [
        'kyc_id',
        'user_id',
        'store_id',
        'status',
        'full_name',
        'date_of_birth',
        'phone_number',
        'employer_name',
        'years_with_employer',
        'state_of_origin',
        'lga_of_origin',
        'community',
        'village',
        'residential_state',
        'residential_lga',
        'residential_address',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'years_with_employer' => 'decimal:1',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Boot method to auto-generate kyc_id
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($application) {
            if (empty($application->kyc_id)) {
                $application->kyc_id = 'KYC_' . strtoupper(Str::random(14));
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'kyc_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LiveFirstKycDocument::class, 'application_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
