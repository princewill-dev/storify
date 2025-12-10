<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vat;

class VatSeeder extends Seeder
{
    public function run(): void
    {
        if (!Vat::query()->exists()) {
            Vat::create([
                'percentage' => 7.50,
                'active' => true,
                'effective_at' => now(),
            ]);
        }
    }
}
