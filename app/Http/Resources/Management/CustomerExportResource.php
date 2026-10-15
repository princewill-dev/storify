<?php

namespace App\Http\Resources\Management;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-56 — one customer as a CSV row.
 *
 * Separate from CustomerParityResource rather than a `toCsvRow()` bolted onto
 * it, even though both describe a customer. A CSV leaves the system: it gets
 * opened in a spreadsheet, mailed around and pasted into something else, and
 * the columns are therefore a contract with people who have never seen this
 * API. The list payload can gain a field without care; this cannot gain a
 * column without asking. Keeping them apart means the list's shape is free to
 * move and the export's is not.
 *
 * `lifetime_value` and `last_order_at` arrive as aggregates the repository
 * attached (withSum/withMax), so this resource never runs a query per row.
 */
final class CustomerExportResource extends JsonResource
{
    /**
     * @var array<int, string>
     */
    public const CSV_HEADERS = [
        'Account ID',
        'First name',
        'Last name',
        'Email',
        'Phone',
        'Account state',
        'Status',
        'Email verified',
        'Orders',
        'Lifetime value',
        'Registered at',
        'Last order at',
        'Joined at',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'account_id' => $customer->account_id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'account_state' => $customer->accountState(),
            'status' => strtolower($customer->status),
            'email_verified' => $customer->hasVerifiedEmail(),
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'lifetime_value' => (float) ($customer->lifetime_value ?? 0),
            'registered_at' => $customer->registered_at?->toISOString(),
            'last_order_at' => $customer->last_order_at,
            'created_at' => $customer->created_at?->toISOString(),
        ];
    }

    /**
     * The same values in CSV_HEADERS order — the two are asserted against each
     * other in the WS-56 admin test, because a column that drifts out of step
     * with its heading mislabels every row under it.
     *
     * @return array<int, mixed>
     */
    public function toCsvRow(): array
    {
        $row = $this->toArray(request());

        return [
            $row['account_id'],
            $row['first_name'],
            $row['last_name'],
            $row['email'],
            $row['phone'],
            $row['account_state'],
            $row['status'],
            // Spelled out rather than 1/0: the reader is a human in a
            // spreadsheet, and "yes"/"no" needs no column-format fixing.
            $row['email_verified'] ? 'yes' : 'no',
            $row['orders_count'],
            number_format($row['lifetime_value'], 2, '.', ''),
            $row['registered_at'],
            $row['last_order_at'],
            $row['created_at'],
        ];
    }
}
