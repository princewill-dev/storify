<?php

namespace Database\Seeders;

use App\Models\Vat;
use Illuminate\Database\Seeder;

class VatSeeder extends Seeder
{
    public function run(): void
    {
        if (! Vat::query()->exists()) {
            Vat::create([
                'percentage' => 7.50,
                'active' => true,
                'effective_at' => now(),
            ]);
        }
    }
}
