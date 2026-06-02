<?php

namespace App\Enums;

enum TransferStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case AWAITING_ACKNOWLEDGMENT = 'awaiting_acknowledgment';
    case DISPATCHED = 'dispatched';
    case RECEIVED = 'received';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::AWAITING_ACKNOWLEDGMENT => 'Awaiting Acknowledgement',
            self::DISPATCHED => 'Dispatched',
            self::RECEIVED => 'Received',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
        };
    }

    public static function badgeData(): array
    {
        $data = [];
        foreach (self::cases() as $status) {
            $data[$status->value] = ['label' => $status->label()];
        }
        return $data;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => $target === self::PENDING,
            self::PENDING => in_array($target, [self::APPROVED, self::AWAITING_ACKNOWLEDGMENT, self::REJECTED]),
            self::APPROVED => in_array($target, [self::DISPATCHED, self::CANCELLED]),
            self::AWAITING_ACKNOWLEDGMENT => $target === self::APPROVED,
            self::DISPATCHED => $target === self::RECEIVED,
            self::RECEIVED => false,
            self::REJECTED => false,
            self::CANCELLED => false,
        };
    }
}
