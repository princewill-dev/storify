<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — the account Paystack resolved for the add-account modal. Paystack
 * may echo back the number it verified; when it does not, the number the
 * caller submitted is returned so the form can keep it.
 */
class ResolvedAccountResource extends JsonResource
{
    public function __construct(array $data, private readonly string $fallbackAccountNumber)
    {
        parent::__construct($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_number' => $this->resource['account_number'] ?? $this->fallbackAccountNumber,
            'account_name' => $this->resource['account_name'] ?? null,
        ];
    }
}
