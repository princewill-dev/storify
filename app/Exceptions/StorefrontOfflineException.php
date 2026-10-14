<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-05 — the storefront is switched off.
 *
 * Thrown when a request reaches a store whose owner has taken its storefront
 * offline. The response shape belongs to the refusal, the way
 * GatewayGrant::refusalReason() owns its sentence, so this class renders
 * itself rather than relying on a branch in a handler.
 *
 * 404, not 410 or 423: a customer probing addresses must learn nothing about
 * which slugs exist, so "off" and "never existed" have to answer identically.
 * The `storefront_offline` marker is what lets the storefront SPA tell the two
 * apart — the same contract StoreWebMetricsController already uses for
 * `no_website`.
 *
 * Laravel honours an exception's own render() without it being registered, so
 * bootstrap/app.php's withExceptions stays empty.
 */
class StorefrontOfflineException extends Exception
{
    public function __construct()
    {
        parent::__construct('This storefront is offline.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['store' => ['storefront_offline']],
        ], 404);
    }
}
