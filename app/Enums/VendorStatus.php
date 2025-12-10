<?php

namespace App\Enums;

enum VendorStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case DELETED = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::DELETED => 'Deleted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-warning text-dark',
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
