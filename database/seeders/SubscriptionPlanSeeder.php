<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::updateOrCreate(
            ['is_default' => true],
            [
                'name' => 'Vendor Yearly Subscription',
                'description' => 'Annual subscription plan for vendor services on the platform',
                'amount' => 50000.00,
                'currency' => 'NGN',
                'interval' => 'yearly',
                'interval_count' => 1,
                'is_active' => true,
                'is_default' => true,
                'features' => [
                    'Create and manage your store',
                    'Unlimited product listings',
                    'Order management dashboard',
                    'Customer management',
                    'Transaction tracking',
                    'Analytics and reports',
                    '24/7 customer support',
                ],
            ]
        );
    }
}
