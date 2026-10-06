<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminOrders;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-5 — the Shop4Me queue (platform-fulfilled orders only).
 *
 * Shares the list serialization with the main admin order index: the legacy
 * Shop4Me view compared the PaymentStatus enum against string cases in its
 *
 * @switch, so every row's badge fell through to "Unpaid". Both screens read
 * the one transaction-derived badge now, and every row links into the shared
 * admin order detail.
 */
class Shop4meOrderController extends ApiController
{
    use EnsuresPlatformAdmin;
    use SerializesAdminOrders;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $this->orderListFilters($request);
        $result = $this->paginateOrderIndex(Order::query()->where('source', 'shop4me'), $filters);

        return $this->ok(
            ['orders' => $result['orders']],
            null,
            200,
            $this->paginationMeta($result['paginator']) + ['stats' => $result['stats']],
        );
    }
}
