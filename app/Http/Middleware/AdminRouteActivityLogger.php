<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminRouteActivityLogger
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        try {
            if (auth()->check()) {
                $user = auth()->user();
                $routeName = \Route::currentRouteName();
                $fullUrl = $request->fullUrl();
                $path = $request->path();
                $method = $request->getMethod();

                ActivityLogger::log(
                    action: 'admin_route_accessed',
                    description: sprintf('%s %s %s (%s)', $user->name ?? 'Unknown', $method, $routeName ?? 'unknown', $fullUrl),
                    metadata: [
                        'role' => $user->role ?? null,
                        'route' => $routeName,
                        'url' => $fullUrl,
                        'path' => $path,
                        'method' => $method,
                        'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'referer' => $request->headers->get('referer'),
                    ],
                    userId: $user->id
                );
            }
        } catch (\Throwable $e) {
            // do not break the request lifecycle if logging fails
        }

        return $response;
    }
}
