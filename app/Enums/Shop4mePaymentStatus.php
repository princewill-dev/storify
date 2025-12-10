<?php

namespace App\Enums;

enum Shop4mePaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PAID = 'paid';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Unpaid',
            self::PAID => 'Paid',
            self::REFUNDED => 'Refunded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::UNPAID => 'bg-warning',
            self::PAID => 'bg-success',
            self::REFUNDED => 'bg-info',
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
