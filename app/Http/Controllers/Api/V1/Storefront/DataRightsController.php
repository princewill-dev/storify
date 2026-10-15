<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\AccountDeletionRequest;
use App\Models\Customer;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use App\Services\Storefront\CustomerDataRightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's data rights — `storefront/account/data-export` and
 * `storefront/account/delete`.
 *
 * Layering: the HTTP shape stays here; the gathered document and the queue
 * entry live in CustomerDataRightsService; the password confirmation in
 * AccountDeletionRequest.
 *
 * The deletion request is deliberately not a deletion. It records the ask,
 * ends every session, and leaves the business to work the queue — so the
 * customer's answer is "we have your request", which is true, rather than "your
 * account is gone", which would not be.
 */
class DataRightsController extends ApiController
{
    public function __construct(
        private readonly CustomerDataRightsService $dataRights,
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function export(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return $this->ok($this->dataRights->export($customer));
    }

    public function destroy(AccountDeletionRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($customer->status === Customer::STATUS_DELETED) {
            return $this->error('This account has already been closed.', 409);
        }

        $customer = $this->dataRights->requestDeletion($customer, $request);

        // Every session ends with the request. The customer has said they are
        // leaving; leaving a token alive on a row that is queued for
        // anonymisation would be leaving the door open behind them.
        $this->tokens->revokeAllAccessTokens($customer, 'customer');
        $this->refreshTokens->revokeAllFor($customer, 'customer');

        return $this->ok([
            'deletion_requested_at' => $customer->deletion_requested_at?->toISOString(),
        ], 'Your request has been recorded. Your account will be closed once the store has processed it, and you have been signed out.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $customer;
    }
}
