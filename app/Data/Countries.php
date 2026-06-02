<?php

namespace App\Data;

class Countries
{
    public static function business(): array
    {
        return [
            'Nigeria',
            'Ghana',
            'Kenya',
        ];
    }

    public static function currencies(): array
    {
        return [
            'NGN' => '₦ — Nigerian Naira (NGN)',
            'USD' => '$ — US Dollar (USD)',
            'KES' => 'KSh — Kenyan Shilling (KES)',
            'GBP' => '£ — British Pound (GBP)',
        ];
    }

    public static function currencySymbol(string $code): string
    {
        return [
            'NGN' => '₦',
            'USD' => '$',
            'KES' => 'KSh',
            'GBP' => '£',
        ][$code] ?? '';
    }
}
