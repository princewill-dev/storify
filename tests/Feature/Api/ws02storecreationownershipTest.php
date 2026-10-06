<?php

use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| WS-02 — store creation ownership (parity port)
|--------------------------------------------------------------------------
| The legacy web test (tests/Feature/Management/StoreCreationTest.php)
| asserted that an authorised owner's new store lands inside their own
| business. The API suite covers the payload, validation and permission
| gate, but nowhere asserted user_id/business_id on the row that creation
| writes, nor that a support_email passed at create time is persisted.
*/

function ws02CreationToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('an authorised owner creating a store owns it inside their business', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $response = $this->withToken(ws02CreationToken($owner))
        ->postJson('/api/v1/management/stores', [
            'name' => 'Victoria Island Store',
            'has_website' => true,
            'is_physical' => true,
            'physical_address' => '12 Test Street, Lagos',
            'support_email' => 'vi-store@example.test',
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Store created successfully!')
        ->assertJsonPath('data.store.name', 'Victoria Island Store')
        ->assertJsonPath('data.store.slug', 'victoria-island-store')
        ->assertJsonPath('data.store.status', Store::STATUS_PENDING)
        ->assertJsonPath('data.store.store_type', 'both')
        ->assertJsonPath('data.store.has_website', true)
        ->assertJsonPath('data.store.physical_address', '12 Test Street, Lagos')
        ->assertJsonPath('data.store.support_email', 'vi-store@example.test');

    $store = Store::where('name', 'Victoria Island Store')->firstOrFail();

    // The legacy suite's core assertion: the row is bound to the creating
    // owner and their business — not floating, not another tenant's.
    expect((int) $store->user_id)->toBe($owner->id)
        ->and((int) $store->business_id)->toBe($business->id)
        ->and($store->status)->toBe(Store::STATUS_PENDING)
        ->and((bool) $store->has_website)->toBeTrue()
        ->and($store->support_email)->toBe('vi-store@example.test');
});
