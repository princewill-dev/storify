<?php

namespace Database\Seeders;

use App\Models\KycDocumentType;
use Illuminate\Database\Seeder;

/**
 * The identity documents an applicant may choose between.
 *
 * ## Two are offered, five exist
 *
 * The registry's own identity numbers are the NIN and the BVN; a passport,
 * a driver's licence or a voter's card proves a name, and this platform needs
 * a number it can be matched against. So those three are seeded **inactive**.
 *
 * Inactive rather than absent, and that is the whole point of seeding them at
 * all. `kyc_applications.kyc_document_type_id` is a foreign key and historical
 * applications name all five; deleting a row would either break those rows or
 * blank a field on a compliance record. `is_active` is the switch the submit
 * path already honours — `KycController` re-validates it server-side, so this
 * is a real restriction and not a hidden dropdown option.
 *
 * The rows are `updateOrCreate`d, so re-running this is what changes an
 * existing install's list — which is exactly how it is meant to be applied.
 */
class KycDocumentTypeSeeder extends Seeder
{
    /** The one place the offered set is stated. */
    private const OFFERED = ['nin', 'bvn'];

    public function run(): void
    {
        $types = [
            ['name' => 'National Identification Number (NIN)', 'code' => 'nin', 'description' => 'NIN slip or card'],
            ['name' => 'Bank Verification Number (BVN)', 'code' => 'bvn', 'description' => 'BVN enrollment slip'],

            // Kept so historical applications still resolve, and so a NIN or
            // BVN applicant who later supplies one of these as a supporting
            // document has something to point at. Not offered.
            ['name' => 'International Passport', 'code' => 'passport', 'description' => 'Valid international passport'],
            ['name' => 'Driver License', 'code' => 'drv_lic', 'description' => 'Government issued driver license'],
            ['name' => 'Voter Card', 'code' => 'voter_card', 'description' => 'Permanent voter card'],
        ];

        foreach ($types as $type) {
            KycDocumentType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'is_active' => in_array($type['code'], self::OFFERED, true),
                ]
            );
        }
    }
}
