<?php

namespace App\Enums;

enum StoreType: string
{
    case ONLINE = 'online';
    case PHYSICAL = 'physical';
    case BOTH = 'both';

    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Online',
            self::PHYSICAL => 'Physical',
            self::BOTH => 'Online & Physical',
        };
    }
}
