<?php

namespace App\Enums;

enum PaymentInterval: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case SIX_MONTHS = '6_months';
    case TWELVE_MONTHS = '12_months';

    public function label(): string
    {
        return match($this) {
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
            self::SIX_MONTHS => '6 Months',
            self::TWELVE_MONTHS => '12 Months',
        };
    }

    public function cycles(): int
    {
        return match($this) {
            self::WEEKLY => 52,
            self::MONTHLY => 12,
            self::SIX_MONTHS => 6,
            self::TWELVE_MONTHS => 12,
        };
    }

    public function description(): string
    {
        return match($this) {
            self::WEEKLY => 'Pay weekly, receive weekly deliveries',
            self::MONTHLY => 'Pay monthly, receive monthly deliveries',
            self::SIX_MONTHS => 'Pay every 6 months, receive monthly deliveries',
            self::TWELVE_MONTHS => 'Pay every 12 months, receive monthly deliveries',
        };
    }
}
