<?php

namespace App\Http\Requests\Admin;

/**
 * WS-18 (admin console) — `PUT /api/v1/admin/company-services/{companyService}`
 * payload.
 *
 * The page-link unique check ignores the bound row, so saving a service's own
 * link back is not a violation.
 */
final class UpdateCompanyServiceRequest extends CompanyServiceWriteRequest
{
    protected function forUpdate(): bool
    {
        return true;
    }
}
