<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Http\Requests\Storefront\SupportRequest;
use App\Services\Storefront\SupportMessageService;
use Illuminate\Http\JsonResponse;

/**
 * The per-store storefront support form
 * (`POST /api/v1/storefront/{store}/support`).
 *
 * Layering: the HTTP shape — the 200, the `{data}` envelope and the exact
 * success message — stays here; the rules live in SupportRequest and the
 * record-and-notify workflow (insert, creation log, customer receipt, store
 * admin notification) in App\Services\Storefront\SupportMessageService. No
 * repository was extracted: the only reads are the shared storefront-context
 * slug lookup in ResolvesStorefrontContext and the workflow's single INSERT,
 * both far below the composition bar. The response carries no data payload,
 * so there is nothing for a resource to shape.
 *
 * `resolveStore()` still fronts the workflow, so an unknown or deleted store
 * 404s before anything is written or mailed. The FormRequest runs earlier,
 * during parameter resolution — the accepted consequence of extracting
 * validation, applied codebase-wide: a malformed payload aimed at an unknown
 * store is answered 422 by validation before the store lookup can 404.
 */
class SupportController extends ApiController
{
    use ResolvesStorefrontContext;

    public function __construct(
        private readonly SupportMessageService $supportMessages,
    ) {}

    public function store(SupportRequest $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $this->supportMessages->record($store, $request->validated());

        return $this->ok([], 'Thank you! Your message has been received.');
    }
}
