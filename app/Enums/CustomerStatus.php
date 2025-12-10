<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case DELETED = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::DELETED => 'Deleted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::ACTIVE => 'bg-success',
            self::SUSPENDED => 'bg-danger',
            self::DELETED => 'bg-secondary',
        };
    }

    public static function badgeData(): array
    {
        $data = [];
        foreach (self::cases() as $status) {
            $data[$status->value] = [
                'label' => $status->label(),
                'class' => $status->badgeClass(),
            ];
        }
        return $data;
    }
}
