<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\UpdateTransactionStatusRequest;
use App\Http\Resources\Admin\TransactionStatusOverrideResource;
use App\Models\Transaction;
use App\Services\Admin\TransactionStatusService;
use Illuminate\Http\JsonResponse;

/**
 * WS-5 — admin transaction status override.
 *
 * A separate one-method controller because the existing
 * Api\V1\Admin\TransactionController is shared by other workstreams and only
 * owns index/show. The route binds {transaction} by its `reference`.
 *
 * The enum-validated override is the only admin lever on money already taken:
 * dashboard/order revenue figures count CONFIRMED totals, so legacy's silent
 * update is now audit-logged (it wrote nothing to ActivityLog) — the write and
 * its audit row share one transaction inside TransactionStatusService.
 *
 * The controller keeps the HTTP shape only — the status code and message
 * string. Validation lives in UpdateTransactionStatusRequest, the
 * transactional write + audit in TransactionStatusService, and response
 * shaping in TransactionStatusOverrideResource. No repository is involved:
 * the route-bound model is a single `update` with no query composition. The
 * platform-admin guard deliberately stays here (not in FormRequest::authorize())
 * so its order relative to route binding is unchanged.
 */
class TransactionStatusController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly TransactionStatusService $statuses,
    ) {}

    public function update(UpdateTransactionStatusRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $transaction = $this->statuses->update(
            $transaction,
            $request->validated(),
            $request->user(),
        );

        return $this->ok(
            ['transaction' => TransactionStatusOverrideResource::make($transaction)->resolve()],
            'Transaction status updated.',
        );
    }
}
