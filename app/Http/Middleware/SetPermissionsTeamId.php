<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user() ?? auth('sanctum')->user();

        if ($user) {
            setPermissionsTeamId($user->business_id);
        }

        return $next($request);
    }
}
