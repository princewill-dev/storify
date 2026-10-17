<?php

namespace App\Support;

/**
 * The one palette every email component draws from.
 *
 * Email templates cannot use raw PHP, so a component cannot compute its own
 * colours inline. Keeping the tones here also means a "warning" callout and a
 * "warning" badge are the same warning, in every template.
 */
final class EmailTone
{
    /** @var array<string, array{bg: string, border: string, text: string, badge_bg: string, badge_text: string}> */
    private const PALETTES = [
        'neutral' => [
            'bg' => '#F1F5F9',
            'border' => '#E2E8F0',
            'text' => '#334155',
            'badge_bg' => '#F1F5F9',
            'badge_text' => '#475569',
        ],
        'info' => [
            'bg' => '#EFF6FF',
            'border' => '#BFDBFE',
            'text' => '#1E40AF',
            'badge_bg' => '#DBEAFE',
            'badge_text' => '#1D4ED8',
        ],
        'success' => [
            'bg' => '#ECFDF5',
            'border' => '#A7F3D0',
            'text' => '#065F46',
            'badge_bg' => '#D1FAE5',
            'badge_text' => '#047857',
        ],
        'warning' => [
            'bg' => '#FFFBEB',
            'border' => '#FDE68A',
            'text' => '#92400E',
            'badge_bg' => '#FEF3C7',
            'badge_text' => '#B45309',
        ],
        'danger' => [
            'bg' => '#FEF2F2',
            'border' => '#FECACA',
            'text' => '#991B1B',
            'badge_bg' => '#FEE2E2',
            'badge_text' => '#B91C1C',
        ],
    ];

    /** @return array{bg: string, border: string, text: string, badge_bg: string, badge_text: string} */
    public static function palette(string $tone): array
    {
        return self::PALETTES[$tone] ?? self::PALETTES['neutral'];
    }

    public static function bg(string $tone): string
    {
        return self::palette($tone)['bg'];
    }

    public static function border(string $tone): string
    {
        return self::palette($tone)['border'];
    }

    public static function text(string $tone): string
    {
        return self::palette($tone)['text'];
    }

    public static function badgeBg(string $tone): string
    {
        return self::palette($tone)['badge_bg'];
    }

    public static function badgeText(string $tone): string
    {
        return self::palette($tone)['badge_text'];
    }
}
