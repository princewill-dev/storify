<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WeightUnitSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $units = [
            ['name' => 'Gram', 'code' => 'g'],
            ['name' => 'Kilogram', 'code' => 'kg'],
            ['name' => 'Pound', 'code' => 'lb'],
            ['name' => 'Ounce', 'code' => 'oz'],
        ];
        foreach ($units as $u) {
            DB::table('weight_units')->updateOrInsert(
                ['code' => $u['code']],
                ['name' => $u['name'], 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }
}
