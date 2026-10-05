<?php

use App\Models\Service;
use App\Models\Store;
use Illuminate\Support\Facades\Mail;

function storefrontServiceContext(): array
{
    [, $business] = createBusinessOwner();

    $store = Store::create([
        'business_id' => $business->id,
        'name' => 'Art Shop',
        'slug' => 'art-shop',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    return [$store, $business];
}

test('storefront services endpoints return active services', function () {
    [$store] = storefrontServiceContext();

    $service = Service::create([
        'store_id' => $store->id,
        'name' => 'Picture Framing',
        'amount' => 2500,
        'status' => 'active',
    ]);

    Service::create([
        'store_id' => $store->id,
        'name' => 'Hidden Service',
        'amount' => 1000,
        'status' => 'inactive',
    ]);

    $this->getJson('/api/v1/storefront/'.$store->slug.'/services')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Picture Framing');

    $this->getJson('/api/v1/storefront/'.$store->slug.'/services/'.$service->slug.'-'.$service->service_code)
        ->assertOk()
        ->assertJsonPath('data.service.name', 'Picture Framing')
        ->assertJsonPath('data.service.currency', '₦');
});

test('storefront support endpoint stores a message', function () {
    Mail::fake();
    [$store] = storefrontServiceContext();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/support', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'phone' => '08000000000',
        'message' => 'Do you deliver?',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Thank you! Your message has been received.');

    $this->assertDatabaseHas('support_messages', [
        'store_id' => $store->id,
        'email' => 'ada@example.com',
        'status' => 'pending',
    ]);
});

test('storefront support endpoint rejects invalid payloads', function () {
    [$store] = storefrontServiceContext();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/support', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'message']);
});
