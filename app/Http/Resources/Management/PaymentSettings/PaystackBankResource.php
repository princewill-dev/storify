<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — a bank entry from Paystack's bank list proxy, exactly like legacy's
 * cached (1 day) `getBanks` call: inactive entries are dropped and rows
 * without a name or code never reach the modal.
 */
class PaystackBankResource extends JsonResource
{
    public function __construct(array $bank)
    {
        parent::__construct($bank);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'] ?? null,
            'code' => $this->resource['code'] ?? null,
            'slug' => $this->resource['slug'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $banks
     * @return array<int, array<string, mixed>>
     */
    public static function fromPayload(array $banks): array
    {
        return collect($banks)
            ->filter(fn ($bank) => ($bank['active'] ?? true) !== false)
            ->filter(fn ($bank) => ($bank['name'] ?? null) && ($bank['code'] ?? null))
            ->map(fn (array $bank) => (new self($bank))->resolve())
            ->values()
            ->all();
    }
}
