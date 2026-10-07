<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Models\PosSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one open POS session in the POS panel (`open_sessions`).
 *
 * @property-read PosSession $resource
 */
final class PosSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PosSession $session */
        $session = $this->resource;

        return [
            'id' => $session->id,
            'session_code' => $session->session_code,
            'store' => $session->store?->name,
            'staff' => $session->staff?->name,
            'opened_at' => $session->opened_at?->toISOString(),
        ];
    }
}
