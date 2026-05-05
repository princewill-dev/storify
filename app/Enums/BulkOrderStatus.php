<?php

namespace App\Enums;

enum BulkOrderStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PAYMENT_PENDING = 'payment_pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';



    public function label(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'Pending Review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::PAYMENT_PENDING => 'Payment Pending',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'bg-secondary',
            self::APPROVED => 'bg-info',
            self::REJECTED => 'bg-danger',
            self::PAYMENT_PENDING => 'bg-warning',
            self::COMPLETED => 'bg-success',
            self::CANCELLED => 'bg-danger',
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
