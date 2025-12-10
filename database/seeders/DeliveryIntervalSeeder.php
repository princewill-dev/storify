<?php

namespace Database\Seeders;

use App\Models\DeliveryInterval;
use Illuminate\Database\Seeder;

class DeliveryIntervalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $intervals = [
            [
                'name' => 'Weekly',
                'slug' => 'weekly',
                'days_count' => 7,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Bi-Weekly (Every 2 Weeks)',
                'slug' => 'bi-weekly',
                'days_count' => 14,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Every 3 Weeks',
                'slug' => 'three-weekly',
                'days_count' => 21,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Monthly (Every 4 Weeks)',
                'slug' => 'monthly',
                'days_count' => 28,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'Every 6 Weeks',
                'slug' => 'six-weekly',
                'days_count' => 42,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'Every 2 Months',
                'slug' => 'bi-monthly',
                'days_count' => 60,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'Quarterly (Every 3 Months)',
                'slug' => 'quarterly',
                'days_count' => 90,
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Every 4 Months',
                'slug' => 'four-monthly',
                'days_count' => 120,
                'is_active' => false,
                'sort_order' => 8,
            ],
            [
                'name' => 'Semi-Annually (Every 6 Months)',
                'slug' => 'semi-annually',
                'days_count' => 180,
                'is_active' => true,
                'sort_order' => 9,
            ],
            [
                'name' => 'Annually (Every 12 Months)',
                'slug' => 'annually',
                'days_count' => 365,
                'is_active' => false,
                'sort_order' => 10,
            ],
        ];

        foreach ($intervals as $interval) {
            DeliveryInterval::updateOrCreate(
                ['slug' => $interval['slug']],
                $interval
            );
        }

        $this->command->info('Delivery intervals seeded successfully!');
    }
}
