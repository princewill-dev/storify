<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SizeUnitSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $units = [
            ['name' => 'Centimeter', 'code' => 'cm'],
            ['name' => 'Inch', 'code' => 'in'],
            ['name' => 'Foot', 'code' => 'ft'],
            ['name' => 'Meter', 'code' => 'm'],
            ['name' => 'Millimeter', 'code' => 'mm'],
        ];
        foreach ($units as $u) {
            DB::table('size_units')->updateOrInsert(
                ['code' => $u['code']],
                ['name' => $u['name'], 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }
}
