<?php

namespace App\Enums;

enum FamilyPackStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case APPROVED = 'approved';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match($this) {
            self::PENDING_REVIEW => 'Pending Review',
            self::APPROVED => 'Approved',
            self::ACTIVE => 'Active',
            self::PAUSED => 'Paused',
            self::CANCELLED => 'Cancelled',
            self::COMPLETED => 'Completed',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING_REVIEW => 'bg-warning',
            self::APPROVED => 'bg-info',
            self::ACTIVE => 'bg-success',
            self::PAUSED => 'bg-secondary',
            self::CANCELLED => 'bg-danger',
            self::COMPLETED => 'bg-dark',
        };
    }
}
