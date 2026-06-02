<?php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $query) {
            $user = auth()->user();

            if (!$user) {
                return;
            }

            if ($user->isPlatformAdmin()) {
                return;
            }

            $query->where($query->qualifyColumn('business_id'), $user->business_id);
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public static function withoutBusinessScope(): Builder
    {
        return static::withoutGlobalScope('business');
    }
}
