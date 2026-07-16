<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::where('is_trial', true)->delete();
        SubscriptionPlan::whereNotIn('name', ['Starter Monthly', 'Starter Annual'])
            ->update(['is_active' => false, 'is_default' => false]);

        SubscriptionPlan::updateOrCreate(
            ['name' => 'Starter Monthly'],
            [
                'plan_code' => Str::random(24),
                'name' => 'Starter Monthly',
                'description' => 'Monthly subscription — full access to all Storify features',
                'amount' => 5000.00,
                'currency' => 'NGN',
                'interval' => 'monthly',
                'interval_count' => 1,
                'is_active' => true,
                'is_default' => false,
                'is_trial' => false,
                'trial_days' => null,
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

        SubscriptionPlan::updateOrCreate(
            ['name' => 'Starter Annual'],
            [
                'plan_code' => Str::random(24),
                'name' => 'Starter Annual',
                'description' => 'Annual subscription — save 17% with yearly billing',
                'amount' => 50000.00,
                'currency' => 'NGN',
                'interval' => 'yearly',
                'interval_count' => 1,
                'is_active' => true,
                'is_default' => true,
                'is_trial' => false,
                'trial_days' => null,
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

        SubscriptionPlan::where('is_default', true)
            ->update(['is_default' => false]);

        SubscriptionPlan::where('name', 'Starter Annual')
            ->update(['is_default' => true]);
    }
}
