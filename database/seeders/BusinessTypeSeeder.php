<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;

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
