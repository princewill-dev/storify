<?php

namespace App\Http\Requests\Admin;

/**
 * WS-15 (admin console) — `PUT /api/v1/admin/products/{product}` payload.
 *
 * Everything is "sometimes": a partial update must not wipe fields the
 * payload never sent (the flags and the bulk fields are applied by the
 * service only when present; legacy's full-form-only assumption read every
 * omission as `false`).
 */
final class UpdateProductRequest extends ProductWriteRequest
{
    protected function forUpdate(): bool
    {
        return true;
    }
}
