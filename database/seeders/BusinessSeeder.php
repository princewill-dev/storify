<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Create platform superadmin
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@storify.test'],
            [
                'name' => 'Platform Admin',
                'uuid' => (string) Str::uuid(),
                'password' => bcrypt('password'),
                'role' => 'superadmin',
                'status' => 'active',
                'is_verified' => true,
                'email_verified_at' => now(),
                'business_id' => null,
            ]
        );

        // Create business owner
        $owner = User::firstOrCreate(
            ['email' => 'owner@storify.test'],
            [
                'name' => 'Demo Owner',
                'uuid' => (string) Str::uuid(),
                'password' => bcrypt('password'),
                'role' => 'business_owner',
                'status' => 'active',
                'is_verified' => true,
                'email_verified_at' => now(),
            ]
        );

        if ($owner->business_id) {
            $business = Business::find($owner->business_id);
        } else {
            $business = Business::create([
                'user_id' => $owner->id,
                'name' => 'Demo Store',
                'slug' => 'demo-store',
                'description' => 'A demo business for testing.',
                'ownership_type_id' => 1,
                'business_type_id' => 1,
                'business_model' => 'both',
                'currency' => 'NGN',
                'physical_store_count' => '1',
                'store_slug' => 'demo',
                'business_location' => 'Lagos, Nigeria',
                'status' => 'active',
            ]);
            $owner->update(['business_id' => $business->id]);
        }

        // Create staff users
        if (User::where('email', 'staff@demo.com')->doesntExist()) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Demo Staff',
                'email' => 'staff@demo.com',
                'password' => bcrypt('password'),
                'role' => 'staff',
                'status' => 'active',
                'is_verified' => true,
                'email_verified_at' => now(),
                'business_id' => $business->id,
            ]);
        }

        if (User::where('email', 'cashier@demo.com')->doesntExist()) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Demo Cashier',
                'email' => 'cashier@demo.com',
                'password' => bcrypt('password'),
                'role' => 'staff',
                'status' => 'active',
                'is_verified' => true,
                'email_verified_at' => now(),
                'business_id' => $business->id,
            ]);
        }

        // Create store
        if (Store::where('store_id', 'DEMO001')->doesntExist()) {
            Store::create([
                'user_id' => $owner->id,
                'business_id' => $business->id,
                'store_id' => 'DEMO001',
                'name' => 'Demo Main Store',
                'slug' => 'demo-main-store',
                'status' => 'active',
                'pos_enabled' => true,
            ]);
        }

        // Create warehouse
        if (Warehouse::where('warehouse_code', 'WH-DEMO-001')->doesntExist()) {
            Warehouse::create([
                'user_id' => $owner->id,
                'business_id' => $business->id,
                'warehouse_code' => 'WH-DEMO-001',
                'name' => 'Demo Warehouse',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'Nigeria',
                'is_active' => true,
            ]);
        }

        // Create trial subscription
        $trialPlan = SubscriptionPlan::where('is_trial', true)->first();
        if ($trialPlan && !Subscription::where('business_id', $business->id)->exists()) {
            Subscription::create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'subscription_plan_id' => $trialPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
        }
    }
}
