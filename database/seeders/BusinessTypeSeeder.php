<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BusinessType;

class BusinessTypeSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            'Retail',
            'Wholesale',
            'Services',
            'Manufacturing',
        ];
        foreach ($items as $name) {
            BusinessType::firstOrCreate(['name' => $name]);
        }
    }
}
