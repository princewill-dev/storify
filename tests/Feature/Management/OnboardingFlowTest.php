<?php

use App\Models\Business;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

test('a verified owner without a business is redirected to business setup', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
    ]);

    actingAs($user)
        ->get(route('management.dashboard'))
        ->assertRedirect(route('management.setup'));
});

test('business setup creates the tenant and advances the owner to plans', function () {
    $user = User::factory()->create([
        'role' => User::ROLE_BUSINESS_OWNER,
        'status' => 'active',
        'is_verified' => true,
    ]);

    actingAs($user)
        ->post(route('management.setup.store'), [
            'name' => 'Northstar Retail',
            'description' => 'A test retail business',
            'business_location' => 'Lagos',
        ])
        ->assertRedirect(route('management.plans.index'));

    $business = Business::where('name', 'Northstar Retail')->firstOrFail();

    expect($user->fresh()->business_id)->toBe($business->id)
        ->and($business->owner->is($user))->toBeTrue();

    assertDatabaseHas('roles', [
        'name' => 'Super Admin',
        'business_id' => $business->id,
    ]);
});
