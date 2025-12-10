<?php

namespace App\Enums;

enum KycDocumentType: string
{
    case NIN = 'nin';
    case PASSPORT = 'passport';
    case PAYSLIP_OLD = 'payslip_old';
    case PAYSLIP_RECENT = 'payslip_recent';
    case VIDEO = 'video';
    case SELFIE = 'selfie';
    case APPOINTMENT_LETTER = 'appointment_letter';
    case BANK_AUTHORIZATION = 'bank_authorization';

    public function label(): string
    {
        return match ($this) {
            self::NIN => 'National ID Number (NIN)',
            self::PASSPORT => 'International Passport',
            self::PAYSLIP_OLD => 'Old Payslip (2+ years)',
            self::PAYSLIP_RECENT => 'Recent Payslip',
            self::VIDEO => 'Live Video',
            self::SELFIE => 'Selfie Photo',
            self::APPOINTMENT_LETTER => 'Appointment Letter',
            self::BANK_AUTHORIZATION => 'Bank Authorization Letter',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NIN => 'Your National Identity Number document',
            self::PASSPORT => 'Your international passport data page',
            self::PAYSLIP_OLD => 'A payslip from at least 2 years ago',
            self::PAYSLIP_RECENT => 'Your most recent payslip',
            self::VIDEO => 'A short video of yourself for verification',
            self::SELFIE => 'A clear selfie photo',
            self::APPOINTMENT_LETTER => 'Your employment appointment letter',
            self::BANK_AUTHORIZATION => 'Authorization letter from bank for automatic deduction',
        };
    }

    public function acceptedFormats(): string
    {
        return match ($this) {
            self::NIN, self::PASSPORT, self::PAYSLIP_OLD, self::PAYSLIP_RECENT,
            self::APPOINTMENT_LETTER, self::BANK_AUTHORIZATION => 'PDF, JPG, PNG',
            self::SELFIE => 'JPG, PNG',
            self::VIDEO => 'MP4, MOV, AVI',
        };
    }

    public function maxSizeMB(): int
    {
        return match ($this) {
            self::VIDEO => 100, // 100MB for videos
            default => 10, // 10MB for documents and images
        };
    }

    public static function allRequired(): array
    {
        return [
            self::NIN->value => self::NIN->label(),
            self::PAYSLIP_OLD->value => self::PAYSLIP_OLD->label(),
            self::PAYSLIP_RECENT->value => self::PAYSLIP_RECENT->label(),
            self::VIDEO->value => self::VIDEO->label(),
            self::SELFIE->value => self::SELFIE->label(),
            self::APPOINTMENT_LETTER->value => self::APPOINTMENT_LETTER->label(),
            self::BANK_AUTHORIZATION->value => self::BANK_AUTHORIZATION->label(),
        ];
    }
}
