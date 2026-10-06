<?php

namespace App\Http\Resources\Management\PaymentSettings;

use App\Services\Management\ResolvedPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — the method header of the method-info screen. `gateway` exposes the
 * masked public key and whether a secret is stored (never the secret itself);
 * `bank` exposes the masked account number and its flags.
 */
class MethodHeaderResource extends JsonResource
{
    use Concerns\MasksGatewayKeys;

    public function __construct(private readonly ResolvedPaymentMethod $resolved)
    {
        parent::__construct($resolved->row);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $method = $this->resolved->method;

        if ($this->resolved->type === 'gateway') {
            $config = json_decode($this->resolved->row->config ?: '{}', true) ?: [];

            return [
                'type' => 'gateway',
                'id' => (int) $this->resolved->row->id,
                'code' => $method->code,
                'name' => $method->name,
                'is_active' => (bool) $this->resolved->row->is_active,
                'public_key_masked' => $this->maskKey($config['public_key'] ?? null),
                'has_secret_key' => ! empty($config['secret_key']),
            ];
        }

        $bank = $this->resolved->row;

        return [
            'type' => 'bank',
            'id' => $bank->id,
            'code' => $method->code,
            'name' => $bank->bank_name,
            'account_name' => $bank->account_name,
            'masked_account_number' => $bank->masked_account_number,
            'is_primary' => (bool) $bank->is_primary,
            'is_verified' => (bool) $bank->is_verified,
        ];
    }
}
