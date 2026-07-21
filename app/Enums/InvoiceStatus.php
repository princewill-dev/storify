<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case OVERDUE = 'overdue';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SENT => 'Sent',
            self::PARTIAL => 'Partial',
            self::PAID => 'Paid',
            self::OVERDUE => 'Overdue',
            self::VOID => 'Void',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::DRAFT => 'bg-slate-100 text-slate-700',
            self::SENT => 'bg-blue-50 text-blue-700',
            self::PARTIAL => 'bg-amber-50 text-amber-700',
            self::PAID => 'bg-emerald-50 text-emerald-700',
            self::OVERDUE => 'bg-red-50 text-red-700',
            self::VOID => 'bg-slate-100 text-slate-500',
        };
    }

    public static function badgeData(): array
    {
        return array_reduce(self::cases(), fn($carry, $status) => $carry + [
            $status->value => ['label' => $status->label(), 'class' => $status->badgeClass()],
        ], []);
    }
}
