<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()) {
            setPermissionsTeamId(auth()->user()->business_id);
        }

        return $next($request);
    }
}
