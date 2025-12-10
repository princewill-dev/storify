<?php

namespace Database\Seeders;

use App\Models\KycDocumentType;
use Illuminate\Database\Seeder;

class KycDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'International Passport', 'code' => 'passport', 'description' => 'Valid international passport'],
            ['name' => 'National Identification Number (NIN)', 'code' => 'nin', 'description' => 'NIN slip or card'],
            ['name' => 'Bank Verification Number (BVN)', 'code' => 'bvn', 'description' => 'BVN enrollment slip'],
            ['name' => 'Driver License', 'code' => 'drv_lic', 'description' => 'Government issued driver license'],
            ['name' => 'Voter Card', 'code' => 'voter_card', 'description' => 'Permanent voter card'],
        ];

        foreach ($types as $type) {
            KycDocumentType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
