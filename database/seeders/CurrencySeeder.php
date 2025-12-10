<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['name' => 'Nigerian Naira', 'code' => 'NGN', 'symbol' => '₦', 'is_default' => true],
            ['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'is_default' => false],
            ['name' => 'Canadian Dollar', 'code' => 'CAD', 'symbol' => 'CA$', 'is_default' => false],
        ];

        // Ensure single default
        foreach ($data as $row) {
            Currency::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'symbol' => $row['symbol'],
                    'is_default' => $row['is_default'],
                ]
            );
        }
        // Enforce only one default
        $default = Currency::where('is_default', true)->first();
        if ($default) {
            Currency::where('id', '!=', $default->id)->update(['is_default' => false]);
        }
    }
}
