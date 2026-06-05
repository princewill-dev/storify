<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;

abstract class Controller
{
    /**
     * Apply business scope to a query for the given user.
     * Platform admins (superadmins) see all data.
     */
    protected function forBusiness(Builder $query, $user): void
    {
        if ($user && !$user->isPlatformAdmin() && $user->business_id) {
            $query->where('business_id', $user->business_id);
        }
    }
}
