<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            CurrencySeeder::class,
            OwnershipTypeSeeder::class,
            BusinessTypeSeeder::class,
            CategorySeeder::class,
            SizeUnitSeeder::class,
            WeightUnitSeeder::class,
            CompanyServiceSeeder::class,
            MainStoreProductSeeder::class,
            VatSeeder::class,
            DeliveryRouteSeeder::class,
            PaymentMethodSeeder::class,
            KycDocumentTypeSeeder::class,
            FeatureSeeder::class,
            SubscriptionPlanSeeder::class,
        ]);
    }
}
