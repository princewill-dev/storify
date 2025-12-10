<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OwnershipType;

class OwnershipTypeSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            'Sole Proprietorship',
            'Partnership',
            'Limited Liability Company',
            'Cooperative',
        ];
        foreach ($items as $name) {
            OwnershipType::firstOrCreate(['name' => $name]);
        }
    }
}
