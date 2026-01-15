<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CONFIRMED = 'confirmed';
    case REFUNDED = 'refunded';
    case CANCELED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PAID => 'Paid',
            self::CONFIRMED => 'Confirmed',
            self::REFUNDED => 'Refunded',
            self::CANCELED => 'Canceled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning light',
            self::PAID => 'badge-success light',
            self::CONFIRMED => 'badge-success light',
            self::REFUNDED => 'badge-info light',
            self::CANCELED => 'badge-danger light',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
