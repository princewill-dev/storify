<?php

namespace App\Actions\Pos;

use App\Models\Order;

final readonly class PosSaleResult
{
    public function __construct(
        public Order $order,
        public bool $replayed = false,
    ) {}
}
