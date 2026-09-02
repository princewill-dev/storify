<?php

namespace Database\Seeders;

use App\Models\OwnershipType;
use Illuminate\Database\Seeder;

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
