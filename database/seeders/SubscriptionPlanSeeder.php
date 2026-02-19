<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // 7-day free trial plan
        SubscriptionPlan::updateOrCreate(
            ['is_trial' => true],
            [
                'name' => 'Free Trial',
                'description' => 'Try Storify free for 7 days — full access to all features',
                'amount' => 0.00,
                'currency' => 'NGN',
                'interval' => 'daily',
                'interval_count' => 7,
                'is_active' => true,
                'is_default' => false,
                'is_trial' => true,
                'trial_days' => 7,
                'sort_order' => 1,
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

        // Yearly paid plan
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
                'is_trial' => false,
                'sort_order' => 2,
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
