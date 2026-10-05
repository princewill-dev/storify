<?php

use App\Models\Business;
use App\Models\Coupon;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;

function adminToken(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function superAdminUser(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

test('the admin dashboard returns platform metrics', function () {
    $admin = superAdminUser();
    [$owner, $business] = createBusinessOwner();

    $response = $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.adminToken($admin)])
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'stats' => [
                    'businesses', 'active_businesses', 'stores', 'active_stores', 'users', 'staff',
                    'customers', 'products', 'low_stock', 'orders', 'orders_today',
                    'revenue_total', 'revenue_today', 'revenue_mtd', 'kyc_pending',
                ],
                'recent_businesses',
                'daily_revenue' => [['date', 'total']],
                'daily_orders' => [['date', 'total']],
                'payment_breakdown',
                'top_stores',
                'revenue_series' => [['month', 'total']],
                'orders_series' => [['month', 'total']],
            ],
        ]);

    expect($response->json('data.daily_revenue'))->toHaveCount(30)
        ->and($response->json('data.daily_orders'))->toHaveCount(30)
        ->and($response->json('data.revenue_series'))->toHaveCount(6)
        ->and($response->json('data.orders_series'))->toHaveCount(6);
});

test('admin can list, suspend and activate businesses', function () {
    $admin = superAdminUser();
    [$owner, $business] = createBusinessOwner();

    $token = adminToken($admin);

    $this->getJson('/api/v1/admin/businesses', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/admin/businesses/'.$business->business_code, ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.business.name', $business->name);

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/suspend', [
        'reason' => 'Policy review',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect($business->fresh()->status)->toBe('suspended');

    $this->postJson('/api/v1/admin/businesses/'.$business->business_code.'/activate', [
        'reason' => 'Cleared',
    ], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect($business->fresh()->status)->toBe('active');
});

test('admin can list users and verify them', function () {
    $admin = superAdminUser();
    [$owner] = createBusinessOwner();

    $token = adminToken($admin);

    $this->getJson('/api/v1/admin/users?role=business_owner', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.email', $owner->email);

    $owner->update(['is_verified' => false, 'email_verified_at' => null]);

    $this->postJson('/api/v1/admin/users/'.$owner->account_code.'/verify', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect($owner->fresh()->is_verified)->toBeTrue();
});

test('admin can manage coupons through the api', function () {
    $admin = superAdminUser();
    $token = adminToken($admin);

    $created = $this->postJson('/api/v1/admin/coupons', [
        'code' => 'api10',
        'name' => 'API Coupon',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'max_uses' => 5,
    ], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()
        ->assertJsonPath('data.coupon.code', 'API10');

    $couponId = $created->json('data.coupon.id');

    $this->getJson('/api/v1/admin/coupons', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.code', 'API10');

    $this->postJson('/api/v1/admin/coupons/'.$couponId.'/toggle', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect(Coupon::find($couponId)->is_active)->toBeFalse();

    $this->deleteJson('/api/v1/admin/coupons/'.$couponId, [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect(Coupon::find($couponId))->toBeNull();
});

test('admin endpoints reject management tokens', function () {
    [$owner] = createBusinessOwner();
    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.$managementToken])
        ->assertStatus(403);
});

test('admin search returns grouped, permission-scoped results', function () {
    $admin = superAdminUser();
    [$owner, $business] = createBusinessOwner();

    $this->getJson('/api/v1/admin/search?q='.urlencode($business->name), ['Authorization' => 'Bearer '.adminToken($admin)])
        ->assertOk()
        ->assertJsonPath('data.businesses.0.name', $business->name)
        ->assertJsonStructure(['data' => ['businesses', 'users', 'stores', 'transactions', 'coupons']]);

    $this->getJson('/api/v1/admin/search?q='.urlencode($owner->email), ['Authorization' => 'Bearer '.adminToken($admin)])
        ->assertOk()
        ->assertJsonPath('data.users.0.email', $owner->email);

    $this->getJson('/api/v1/admin/search?q=x', ['Authorization' => 'Bearer '.adminToken($admin)])
        ->assertOk()
        ->assertJsonPath('data.businesses', []);
});

test('admin list endpoints support sorting and the dashboard accepts a range', function () {
    $admin = superAdminUser();
    createBusinessOwner(['name' => 'Zed Owner']);
    createBusinessOwner(['name' => 'Alpha Owner']);

    $token = adminToken($admin);

    $this->getJson('/api/v1/admin/businesses?sort=name&direction=asc', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.name', $business = Business::orderBy('name')->first()->name);

    $dashboard = $this->getJson('/api/v1/admin/dashboard?days=7', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.range_days', 7);

    expect($dashboard->json('data.daily_revenue'))->toHaveCount(7)
        ->and($dashboard->json('data.daily_orders'))->toHaveCount(7);
});
