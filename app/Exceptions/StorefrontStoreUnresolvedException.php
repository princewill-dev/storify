<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * No business could be determined for a storefront auth request.
 *
 * A customer account belongs to exactly one business, so sign-up, sign-in and
 * password reset are meaningless without knowing which. The canonical routes
 * carry the store in the path; this is what the transitional routes answer
 * when neither the body, the header, the `Origin` host nor the account itself
 * names one.
 *
 * 422 rather than 404, deliberately, and that is the difference from
 * StorefrontOfflineException. A 404 would say "no such store", which is a
 * statement about a slug; this is a malformed request that names no store at
 * all. Rendering it here rather than in bootstrap/app.php keeps the refusal
 * owned by the class that describes it.
 *
 * `store_ambiguous` is a different failure with the same envelope (see
 * `CustomerAuthController`): the caller named a store, but the account is
 * claimable from more than one and the server will not pick for them.
 */
class StorefrontStoreUnresolvedException extends Exception
{
    public function __construct(private readonly string $marker = 'store_unresolved')
    {
        parent::__construct('We could not tell which store this request is for.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['store' => [$this->marker]],
        ], 422);
    }
}
