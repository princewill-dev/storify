<?php

namespace App\Models;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Scope queries to a specific business.
     */
    public function scopeForBusiness(Builder $query, ?int $businessId): void
    {
        if ($businessId) {
            $query->where($query->qualifyColumn('business_id'), $businessId);
        }
    }
}
