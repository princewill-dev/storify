<?php

use App\Mail\SupportMessageReceipt;
use App\Models\CompanyService;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Mail;

test('home endpoint returns company, stats, plans, testimonials, services and stores', function () {
    [, $business] = createBusinessOwner();

    $store = Store::create([
        'business_id' => $business->id,
        'name' => 'Store One',
        'slug' => 'store-one',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Product One',
        'amount' => 1000,
        'quantity' => 5,
        'status' => 'active',
    ]);

    SubscriptionPlan::create([
        'name' => 'Starter',
        'amount' => 5000,
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
        'is_active' => true,
        'is_trial' => false,
        'features' => ['Feature A'],
        'sort_order' => 1,
    ]);

    Testimonial::create([
        'name' => 'Ada',
        'photo' => 'testimonials/ada.jpg',
        'occupation' => 'Founder',
        'message' => 'Great platform',
        'status' => 'active',
    ]);

    CompanyService::create([
        'title' => 'Store Setup',
        'description' => 'We help you set up',
        'status' => 'active',
        'order' => 1,
    ]);

    $this->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.stats.stores', 1)
        ->assertJsonPath('data.stats.products', 1)
        ->assertJsonPath('data.plans.0.name', 'Starter')
        ->assertJsonPath('data.testimonials.0.name', 'Ada')
        ->assertJsonPath('data.services.0.title', 'Store Setup')
        ->assertJsonPath('data.stores.0.slug', 'store-one')
        ->assertJsonStructure([
            'data' => [
                'company' => ['name', 'logo_url', 'seo'],
                'stats' => ['stores', 'products'],
                'plans',
                'testimonials',
                'services',
                'stores',
            ],
        ]);
});

test('home support endpoint accepts a valid message', function () {
    Mail::fake();

    $this->postJson('/api/v1/home/support', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'phone' => '08000000000',
        'subject' => 'Question',
        'message' => 'How do I start?',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Your message has been received. We will get back to you shortly.');

    Mail::assertQueued(SupportMessageReceipt::class);
});

test('home support endpoint rejects invalid payloads', function () {
    $this->postJson('/api/v1/home/support', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'phone', 'subject', 'message']);
});
