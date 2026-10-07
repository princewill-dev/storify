<?php

namespace App\Http\Requests\Admin;

/**
 * WS-18 (admin console) — `POST /api/v1/admin/company-services` payload.
 */
final class StoreCompanyServiceRequest extends CompanyServiceWriteRequest
{
    protected function forUpdate(): bool
    {
        return false;
    }
}
