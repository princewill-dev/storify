<?php

namespace App\Enums;

enum LiveFirstStatus: string
{
    case NOT_ENROLLED = 'not_enrolled';
    case PENDING_VERIFICATION = 'pending_verification';
    case VERIFIED = 'verified';
    case TESTING = 'testing';
    case TESTED = 'tested';
    case APPROVED = 'approved';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::NOT_ENROLLED => 'Not Enrolled',
            self::PENDING_VERIFICATION => 'Pending Verification',
            self::VERIFIED => 'Verified',
            self::TESTING => 'Testing Period',
            self::TESTED => 'Tested',
            self::APPROVED => 'Approved',
            self::SUSPENDED => 'Suspended',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NOT_ENROLLED => 'bg-secondary',
            self::PENDING_VERIFICATION => 'bg-warning',
            self::VERIFIED => 'bg-info',
            self::TESTING => 'bg-primary',
            self::TESTED => 'bg-success',
            self::APPROVED => 'bg-success',
            self::SUSPENDED => 'bg-danger',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NOT_ENROLLED => 'Customer has not enrolled in Live First program',
            self::PENDING_VERIFICATION => 'KYC documents submitted, awaiting admin verification',
            self::VERIFIED => 'KYC approved, can start 6-month testing period',
            self::TESTING => 'Currently in 6-month testing period',
            self::TESTED => 'Completed 6-month testing successfully',
            self::APPROVED => 'Fully approved, can buy on credit',
            self::SUSPENDED => 'Credit privileges suspended due to default',
        };
    }

    public static function badgeData(): array
    {
        $data = [];
        foreach (self::cases() as $status) {
            $data[$status->value] = [
                'label' => $status->label(),
                'class' => $status->badgeClass(),
                'description' => $status->description(),
            ];
        }

        return $data;
    }
}
