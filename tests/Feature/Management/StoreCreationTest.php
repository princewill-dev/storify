<?php

use App\Models\Store;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

test('an authorized owner can create a store inside their business', function () {
    [$user, $business] = createBusinessOwner([
        'trial_ends_at' => now()->addWeek(),
    ]);

    expect($user->getRoleNames()->all())->toContain('Super Admin')
        ->and($user->can('stores create'))->toBeTrue();

    $response = actingAs($user)->post(route('management.stores.store'), [
        'name' => 'Victoria Island Store',
        'has_website' => true,
        'is_physical' => true,
        'physical_address' => '12 Test Street, Lagos',
        'support_email' => 'vi-store@example.test',
    ]);

    $response->assertSessionHasNoErrors();

    $store = Store::where('name', 'Victoria Island Store')->firstOrFail();

    $response->assertRedirect(route('management.stores.show', $store));
    assertDatabaseHas('stores', [
        'id' => $store->id,
        'user_id' => $user->id,
        'business_id' => $business->id,
        'status' => Store::STATUS_PENDING,
        'has_website' => true,
    ]);
});
