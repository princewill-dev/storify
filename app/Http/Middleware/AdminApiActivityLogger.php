<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WS-1 — writes an `admin_route_accessed` audit row for every request that
 * reaches the admin API: the legacy "who looked at what" trail. This fixes
 * the legacy blind spot where /office/dashboard and /office/settings sat
 * outside the logger group and were never written to the table.
 *
 * WIRING (orchestrator — this middleware belongs on a shared file):
 * apply it to the whole admin group in routes/api/v1/admin.php, after
 * auth:sanctum so the actor is resolved, e.g.
 *
 *     Route::middleware(['auth:sanctum', 'token.audience:admin', 'team.context', AdminApiActivityLogger::class])
 *
 * or register it in bootstrap/app.php via
 * $middleware->alias(['admin.api.activity' => AdminApiActivityLogger::class])
 * and add 'admin.api.activity' to that group. It must wrap the dashboard and
 * settings routes too — that is the point of the fix.
 *
 * The activity-logs route (ad01-activity-log.php) already carries the
 * middleware directly so viewing the log is itself audited even before the
 * group wiring lands. The per-request attribute below means a route-level
 * plus group-level application writes exactly one row, never two.
 *
 * Failures are swallowed: audit logging must never break a request.
 */
class AdminApiActivityLogger
{
    /**
     * Request attribute marking that this request already produced a row.
     */
    public const LOGGED_ATTRIBUTE = 'admin_api_activity_logged';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->log($request, $response);
        } catch (\Throwable $e) {
            // do not break the request lifecycle if logging fails
        }

        return $response;
    }

    /**
     * Write the audit row once per request, after auth has resolved.
     */
    protected function log(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::LOGGED_ATTRIBUTE)) {
            return;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $request->attributes->set(self::LOGGED_ATTRIBUTE, true);

        $routeName = $request->route()?->getName();
        $method = $request->getMethod();
        $url = $request->fullUrl();

        ActivityRecorder::record(
            action: 'admin_route_accessed',
            description: sprintf('%s %s %s (%s)', $user->name ?? 'Unknown', $method, $routeName ?? 'unknown', $url),
            metadata: [
                'role' => $user->role ?? null,
                'route' => $routeName,
                'url' => $url,
                'path' => $request->path(),
                'method' => $method,
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'referer' => $request->headers->get('referer'),
            ],
            actor: $user,
        );
    }
}
