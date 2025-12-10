<?php

namespace App\Enums;

enum Shop4meRequestStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case FILLED = 'filled';
    case DISPATCHED = 'dispatched';
    case DELIVERED = 'delivered';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
            self::FILLED => 'Filled',
            self::DISPATCHED => 'Dispatched',
            self::DELIVERED => 'Delivered',
            self::CLOSED => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-warning text-dark',
            self::ACCEPTED => 'bg-success',
            self::REJECTED => 'bg-danger',
            self::FILLED => 'bg-primary',
            self::DISPATCHED => 'bg-info',
            self::DELIVERED => 'bg-success',
            self::CLOSED => 'bg-secondary',
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
