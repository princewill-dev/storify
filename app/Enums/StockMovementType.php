<?php

namespace App\Enums;

enum StockMovementType: string
{
    case ADDED = 'added';
    case REMOVED = 'removed';
    case TRANSFERRED = 'transferred';
    case ADJUSTED = 'adjusted';

    public function label(): string
    {
        return match ($this) {
            self::ADDED => 'Stock Added',
            self::REMOVED => 'Stock Removed',
            self::TRANSFERRED => 'Stock Transferred',
            self::ADJUSTED => 'Stock Adjusted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::ADDED => 'bg-success',
            self::REMOVED => 'bg-danger',
            self::TRANSFERRED => 'bg-info',
            self::ADJUSTED => 'bg-warning text-dark',
        };
    }

    public static function badgeData(): array
    {
        $data = [];
        foreach (self::cases() as $type) {
            $data[$type->value] = [
                'label' => $type->label(),
                'class' => $type->badgeClass(),
            ];
        }
        return $data;
    }
}
