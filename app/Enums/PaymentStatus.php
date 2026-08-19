<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case UNPAID = 'unpaid';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::UNPAID => 'Unpaid',
            self::PARTIAL => 'Partially Paid',
            self::PAID => 'Paid',
            self::REFUNDED => 'Refunded',
            self::FAILED => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-secondary',
            self::UNPAID => 'bg-warning',
            self::PARTIAL => 'bg-info',
            self::PAID => 'bg-success',
            self::REFUNDED => 'bg-info',
            self::FAILED => 'bg-danger',
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
