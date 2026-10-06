<?php

use App\Mail\SupportMessageReplyMail;
use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\User;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Mail;

function ws33Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws33Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Support Store',
        'slug' => fake()->unique()->slug(3),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ws33Message(Store $store, array $attributes = []): SupportMessage
{
    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $message = SupportMessage::create(array_merge([
        'store_id' => $store->id,
        'name' => 'Ada Customer',
        'email' => 'ada@example.com',
        'phone' => '08030000000',
        'message' => 'Where is my order?',
        'status' => 'pending',
    ], $attributes));

    // created_at is not fillable; ordering assertions need distinct stamps.
    if ($createdAt !== null) {
        $message->forceFill(['created_at' => $createdAt])->save();
    }

    return $message;
}

test('the support inbox lists messages with store, phone, status and counts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws33Store($owner, ['name' => 'Ikeja Branch']);

    ws33Message($store, ['name' => 'Ada Customer']);
    ws33Message($store, ['name' => 'Bola Buyer', 'status' => 'replied', 'reply' => 'It ships today.']);

    $response = $this->withToken(ws33Token($owner))->getJson('/api/v1/management/support-messages');

    $response->assertOk()
        ->assertJsonPath('data.messages.0.name', 'Ada Customer')
        ->assertJsonPath('data.messages.0.phone', '08030000000')
        ->assertJsonPath('data.messages.0.status', 'pending')
        ->assertJsonPath('data.messages.0.store.name', 'Ikeja Branch')
        ->assertJsonPath('data.counts.pending', 1)
        ->assertJsonPath('data.counts.replied', 1)
        ->assertJsonPath('data.counts.total', 2)
        ->assertJsonPath('data.stores.0.id', $store->id)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonStructure([
            'data' => [
                'messages' => [['id', 'name', 'email', 'phone', 'message', 'status', 'reply', 'replied_at', 'created_at', 'store']],
                'stores',
                'counts',
            ],
        ]);
});

test('the inbox only returns messages for the business accessible stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $mine = ws33Store($owner, ['name' => 'Mine']);
    $theirs = ws33Store($otherOwner, ['name' => 'Theirs']);

    ws33Message($mine, ['name' => 'My Customer']);
    ws33Message($theirs, ['name' => 'Their Customer']);

    $response = $this->withToken(ws33Token($owner))->getJson('/api/v1/management/support-messages');

    $response->assertOk();
    expect(array_column($response->json('data.messages'), 'name'))->toBe(['My Customer'])
        ->and($response->json('data.counts.total'))->toBe(1);
});

test('the inbox excludes messages belonging to deleted stores', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $live = ws33Store($owner, ['name' => 'Live']);
    $deleted = ws33Store($owner, ['name' => 'Gone', 'status' => Store::STATUS_DELETED]);

    ws33Message($live, ['name' => 'Kept']);
    ws33Message($deleted, ['name' => 'Leaked']);

    $response = $this->withToken(ws33Token($owner))->getJson('/api/v1/management/support-messages');

    $response->assertOk();
    expect(array_column($response->json('data.messages'), 'name'))->toBe(['Kept']);
});

test('the inbox orders pending messages first', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws33Store($owner);

    ws33Message($store, ['name' => 'Closed First', 'status' => 'closed', 'created_at' => now()->subDay()]);
    ws33Message($store, ['name' => 'Replied Second', 'status' => 'replied', 'created_at' => now()]);
    ws33Message($store, ['name' => 'Pending Last', 'status' => 'pending', 'created_at' => now()->subHour()]);

    $response = $this->withToken(ws33Token($owner))->getJson('/api/v1/management/support-messages');

    // Legacy ordered `status asc` alphabetically, burying pending below closed.
    expect(array_column($response->json('data.messages'), 'name'))
        ->toBe(['Pending Last', 'Replied Second', 'Closed First']);
});

test('the inbox filters by status, store and search term', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $storeA = ws33Store($owner, ['name' => 'Alpha']);
    $storeB = ws33Store($owner, ['name' => 'Beta']);

    ws33Message($storeA, ['name' => 'Ada Needs Help', 'status' => 'pending', 'created_at' => now()->subMinutes(30)]);
    ws33Message($storeA, ['name' => 'Bola', 'status' => 'replied', 'created_at' => now()->subMinutes(20)]);
    ws33Message($storeB, ['name' => 'Chidi', 'status' => 'pending', 'created_at' => now()->subMinutes(10)]);

    $token = ws33Token($owner);

    $pending = $this->withToken($token)->getJson('/api/v1/management/support-messages?status=pending');
    expect(array_column($pending->json('data.messages'), 'name'))->toBe(['Chidi', 'Ada Needs Help']);

    $storeOnly = $this->withToken($token)->getJson('/api/v1/management/support-messages?store_id='.$storeB->id);
    expect(array_column($storeOnly->json('data.messages'), 'name'))->toBe(['Chidi']);

    $searched = $this->withToken($token)->getJson('/api/v1/management/support-messages?q=Ada');
    expect(array_column($searched->json('data.messages'), 'name'))->toBe(['Ada Needs Help']);

    $this->withToken($token)->getJson('/api/v1/management/support-messages?status=nonsense')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    // An inaccessible store filter is rejected, not silently ignored.
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $theirs = ws33Store($otherOwner);

    $this->withToken($token)->getJson('/api/v1/management/support-messages?store_id='.$theirs->id)
        ->assertStatus(422);
});

test('replying flips the status, records the business actor and emails the customer', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws33Store($owner);
    $message = ws33Message($store);

    $response = $this->withToken(ws33Token($owner))
        ->postJson("/api/v1/management/support-messages/{$message->id}/reply", [
            'reply' => 'Your parcel leaves the depot today.',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.message.status', 'replied')
        ->assertJsonPath('data.message.reply', 'Your parcel leaves the depot today.')
        ->assertJsonPath('data.message.replied_by_type', 'business')
        ->assertJsonPath('data.message.replied_by_id', $owner->id)
        ->assertJsonPath('data.message.replied_by_name', $owner->name)
        ->assertJsonPath('message', 'Reply sent successfully to the customer.');

    expect($response->json('data.message.replied_at'))->not->toBeNull();

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('replied')
        ->and($fresh->reply)->toBe('Your parcel leaves the depot today.')
        ->and($fresh->replied_by_id)->toBe($owner->id)
        ->and($fresh->replied_at)->not->toBeNull();

    Mail::assertQueued(SupportMessageReplyMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));

    expect(ActivityLog::where('action', 'support_message_replied')
        ->where('metadata->support_message_id', $message->id)
        ->exists())->toBeTrue();
});

test('a reply is required and cannot exceed 2000 characters', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $message = ws33Message(ws33Store($owner));

    $token = ws33Token($owner);
    $url = "/api/v1/management/support-messages/{$message->id}/reply";

    $this->withToken($token)->postJson($url, ['reply' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reply');

    $this->withToken($token)->postJson($url, ['reply' => str_repeat('a', 2001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reply');

    // The max boundary itself is accepted.
    $this->withToken($token)->postJson($url, ['reply' => str_repeat('a', 2000)])
        ->assertOk();

    expect($message->fresh()->status)->toBe('replied');
});

test('a message belonging to another business cannot be read or replied to', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    [$otherOwner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $theirs = ws33Message(ws33Store($otherOwner));

    $token = ws33Token($owner);

    $this->withToken($token)->getJson("/api/v1/management/support-messages/{$theirs->id}")
        ->assertStatus(403);

    $this->withToken($token)->postJson("/api/v1/management/support-messages/{$theirs->id}/reply", [
        'reply' => 'Trying to cross tenants.',
    ])->assertStatus(403);

    Mail::assertNothingQueued();

    expect($theirs->fresh()->status)->toBe('pending')
        ->and($theirs->fresh()->reply)->toBeNull();
});

test('a closed conversation refuses new replies', function () {
    Mail::fake();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $message = ws33Message(ws33Store($owner), ['status' => 'closed']);

    $this->withToken(ws33Token($owner))
        ->postJson("/api/v1/management/support-messages/{$message->id}/reply", ['reply' => 'Too late?'])
        ->assertStatus(422);

    Mail::assertNothingQueued();

    expect($message->fresh()->status)->toBe('closed')
        ->and($message->fresh()->reply)->toBeNull();
});

test('the message detail endpoint returns one message', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws33Store($owner, ['name' => 'Detail Store']);
    $message = ws33Message($store, ['phone' => '08123456789']);

    $this->withToken(ws33Token($owner))
        ->getJson("/api/v1/management/support-messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('data.message.id', $message->id)
        ->assertJsonPath('data.message.phone', '08123456789')
        ->assertJsonPath('data.message.store.name', 'Detail Store');

    $this->withToken(ws33Token($owner))
        ->getJson('/api/v1/management/support-messages/999999')
        ->assertStatus(404);
});

test('the stats endpoint counts messages by status', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ws33Store($owner);

    ws33Message($store, ['status' => 'pending']);
    ws33Message($store, ['status' => 'pending']);
    ws33Message($store, ['status' => 'replied']);
    ws33Message($store, ['status' => 'closed']);

    $this->withToken(ws33Token($owner))
        ->getJson('/api/v1/management/support-messages/stats')
        ->assertOk()
        ->assertJsonPath('data.counts.pending', 2)
        ->assertJsonPath('data.counts.replied', 1)
        ->assertJsonPath('data.counts.closed', 1)
        ->assertJsonPath('data.counts.total', 4);
});

test('staff without the support permissions cannot read or reply', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $message = ws33Message(ws33Store($owner));

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Associate');

    $token = ws33Token($staff);

    $this->withToken($token)->getJson('/api/v1/management/support-messages')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/management/support-messages/stats')->assertStatus(403);
    $this->withToken($token)->postJson("/api/v1/management/support-messages/{$message->id}/reply", [
        'reply' => 'Not allowed.',
    ])->assertStatus(403);

    Mail::assertNothingQueued();
});

test('a restricted support agent only reaches messages of assigned stores', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $assigned = ws33Store($owner, ['name' => 'Assigned']);
    $unassigned = ws33Store($owner, ['name' => 'Unassigned']);

    $reachable = ws33Message($assigned, ['name' => 'Reachable']);
    $hidden = ws33Message($unassigned, ['name' => 'Hidden']);

    $agent = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => $business->id,
    ]);

    (new SpatiePermissionSeeder)->run();
    setPermissionsTeamId($business->id);
    $agent->assignRole('Customer Support');
    $agent->assignedStores()->attach($assigned->id);

    $token = ws33Token($agent);

    $response = $this->withToken($token)->getJson('/api/v1/management/support-messages');

    $response->assertOk();
    expect(array_column($response->json('data.messages'), 'name'))->toBe(['Reachable'])
        ->and($response->json('data.counts.pending'))->toBe(1);

    $this->withToken($token)->getJson("/api/v1/management/support-messages/{$hidden->id}")
        ->assertStatus(403);

    // The agent can work the message it can see...
    $this->withToken($token)
        ->postJson("/api/v1/management/support-messages/{$reachable->id}/reply", ['reply' => 'On it.'])
        ->assertOk();

    // ...but not the one belonging to a store it is not assigned to.
    $this->withToken($token)
        ->postJson("/api/v1/management/support-messages/{$hidden->id}/reply", ['reply' => 'No access.'])
        ->assertStatus(403);

    expect($hidden->fresh()->status)->toBe('pending');
});
