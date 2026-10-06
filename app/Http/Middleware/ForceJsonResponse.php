<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Treat every request to this API surface as a JSON request.
 *
 * Laravel only converts a ValidationException into the documented
 * {message, errors} 422 payload when the request negotiates JSON. A multipart
 * upload (e.g. a bank-account logo posted with a file) from a client that did
 * not set `Accept: application/json` would instead be answered with a 302
 * redirect-back and the errors flashed to the session — never an acceptable
 * response from an API route, and useless to the SPA. Forcing the header here
 * keeps validation failures on the API's JSON contract.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
