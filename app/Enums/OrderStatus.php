<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case PROCESSING = 'processing';
    case DISPATCHED = 'dispatched';
    case DELIVERED = 'delivered';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACCEPTED => 'Accepted',
            self::PROCESSING => 'Processing',
            self::DISPATCHED => 'Dispatched',
            self::DELIVERED => 'Delivered',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::RETURNED => 'Returned',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-secondary',
            self::ACCEPTED => 'bg-info',
            self::PROCESSING => 'bg-primary',
            self::DISPATCHED => 'bg-warning',
            self::DELIVERED => 'bg-success',
            self::COMPLETED => 'bg-success',
            self::CANCELLED => 'bg-danger',
            self::RETURNED => 'bg-danger',
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
