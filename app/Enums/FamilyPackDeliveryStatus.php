<?php

namespace App\Enums;

enum FamilyPackDeliveryStatus: string
{
    case PENDING = 'pending';
    case PAYMENT_PENDING = 'payment_pending';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case DELIVERED = 'delivered';
    case SKIPPED = 'skipped';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PAYMENT_PENDING => 'Payment Pending',
            self::PAID => 'Paid',
            self::PROCESSING => 'Processing',
            self::DELIVERED => 'Delivered',
            self::SKIPPED => 'Skipped',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-secondary',
            self::PAYMENT_PENDING => 'bg-warning',
            self::PAID => 'bg-success',
            self::PROCESSING => 'bg-info',
            self::DELIVERED => 'bg-primary',
            self::SKIPPED => 'bg-light text-dark',
            self::CANCELLED => 'bg-danger',
        };
    }
}
