<?php

namespace App\Http\Requests\Admin;

/**
 * WS-15 (admin console) — `POST /api/v1/admin/products` payload.
 */
final class StoreProductRequest extends ProductWriteRequest
{
    protected function forUpdate(): bool
    {
        return false;
    }
}
