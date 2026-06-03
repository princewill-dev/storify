<?php

use App\Models\User;
use App\Models\Business;
use function Pest\Laravel\{actingAs, get, seed};

beforeEach(function () {
    seed(\Database\Seeders\SpatiePermissionSeeder::class);
});

afterEach(function () {
    User::where('email', 'like', '%.test')->delete();
});

function makeStaffUser(string $role): User
{
    $business = Business::first();
    if (!$business) {
        $business = Business::factory()->create(['status' => 'active']);
    }
    $user = User::create([
        'name' => 'Test ' . \Illuminate\Support\Str::random(6),
        'email' => \Illuminate\Support\Str::random(8) . '@rbac.test',
        'password' => bcrypt('password'),
        'role' => 'staff',
        'business_id' => $business->id,
        'is_verified' => true,
        'status' => 'active',
    ]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    return $user;
}

// ============================================================
// DASHBOARD ACCESS
// ============================================================

test('cashier can access management dashboard', function () {
    actingAs(makeStaffUser('Cashier'))->get(route('management.dashboard'))->assertOk();
});

test('warehouse manager can access management dashboard', function () {
    actingAs(makeStaffUser('Warehouse Manager'))->get(route('management.dashboard'))->assertOk();
});

test('store associate can access management dashboard', function () {
    actingAs(makeStaffUser('Store Associate'))->get(route('management.dashboard'))->assertOk();
});

// ============================================================
// SIDEBAR VISIBILITY
// ============================================================

test('cashier sees only gated sidebar sections', function () {
    $r = actingAs(makeStaffUser('Cashier'))->get(route('management.dashboard'))->assertOk();
    $r->assertSee('Products');
    $r->assertSee('POS');
    $r->assertSee('Customers');
    $r->assertDontSee('Stock Transfers');
});

test('warehouse manager sees warehouse gated sections', function () {
    $r = actingAs(makeStaffUser('Warehouse Manager'))->get(route('management.dashboard'))->assertOk();
    $r->assertSee('Warehouses');
    $r->assertDontSee('Payment Settings');
});

// ============================================================
// KPI CARD GATING
// ============================================================

test('cashier does not see revenue or staff KPI cards', function () {
    $r = actingAs(makeStaffUser('Cashier'))->get(route('management.dashboard'))->assertOk();
    $r->assertDontSee('Total Revenue');
    $r->assertDontSee('Total Staff');
    $r->assertDontSee('Recent Transactions');
});

test('accountant sees revenue and transactions but not staff', function () {
    $r = actingAs(makeStaffUser('Accountant'))->get(route('management.dashboard'))->assertOk();
    $r->assertSee('Total Revenue');
    $r->assertSee('Recent Transactions');
    $r->assertDontSee('Total Staff');
});

// ============================================================
// ROUTE MIDDLEWARE
// ============================================================

test('cashier cannot access transactions page', function () {
    actingAs(makeStaffUser('Cashier'))->get(route('management.transactions.index'))->assertForbidden();
});

test('cashier cannot access payment settings', function () {
    actingAs(makeStaffUser('Cashier'))->get(route('management.payment-settings.index'))->assertForbidden();
});

test('warehouse manager cannot create staff', function () {
    actingAs(makeStaffUser('Warehouse Manager'))->get(route('management.staff.create'))->assertForbidden();
});

test('store associate cannot create products', function () {
    actingAs(makeStaffUser('Store Associate'))->get(route('management.products.create'))->assertForbidden();
});

// ============================================================
// ALL ROLES CAN REACH DASHBOARD
// ============================================================

test('all staff roles can reach dashboard', function (string $role) {
    actingAs(makeStaffUser($role))->get(route('management.dashboard'))->assertOk();
})->with([
    'Cashier', 'Warehouse Manager', 'Store Manager', 'Accountant',
    'Inventory Clerk', 'Customer Support', 'Delivery Agent', 'Store Associate',
    'Auditor', 'Manager', 'Chief Financial Officer', 'Managing Director',
]);
