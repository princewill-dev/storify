<?php

use App\Mail\AdminNewSupportMessageMail;
use App\Mail\SupportMessageReplyMail;
use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\User;
use App\Providers\SupportInboxServiceProvider;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * WS-16 — support inbox (admin console).
 *
 * Covers the inbox list (platform-wide, pending-first, counters, store
 * context, search/status/store/date filters, pagination), the detail thread
 * with the replier label, the reply flow (validation, status flip, customer
 * email, audit), the explicit re-reply guard against legacy defect #16, the
 * revived close/re-open actions, the audited hard delete, the platform-admin
 * and permission boundaries, and the platform-office notification for new
 * storefront messages.
 */
function ad16Token(User $user, string $audience = 'admin'): string
{
    return $user->createToken('admin-access', [$audience], now()->addHour())->plainTextToken;
}

function ad16SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad16AdminWithRole(string $roleName): User
{
    (new SpatiePermissionSeeder)->run();

    $user = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $user->assignRole($roleName);

    return $user;
}

function ad16Store(User $owner, array $attributes = []): Store
{
    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Support Store',
        'slug' => fake()->unique()->slug(3),
        'status' => Store::STATUS_ACTIVE,
    ], $attributes));
}

function ad16Message(Store $store, array $attributes = []): SupportMessage
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

function ad16Get(object $test, User $admin, string $path)
{
    return $test->getJson('/api/v1/admin/'.$path, ['Authorization' => 'Bearer '.ad16Token($admin)]);
}

function ad16Post(object $test, User $admin, string $path, array $payload = [])
{
    return $test->postJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad16Token($admin)]);
}

function ad16Delete(object $test, User $admin, string $path)
{
    return $test->deleteJson('/api/v1/admin/'.$path, [], ['Authorization' => 'Bearer '.ad16Token($admin)]);
}

/**
 * The observer that notifies the platform office is registered by
 * SupportInboxServiceProvider, which the orchestrator mounts in
 * bootstrap/providers.php. Register it here the same way when the mounting
 * line has not landed yet, without double-registering once it has.
 */
function ad16EnsureSupportObserverRegistered(): void
{
    if (! Event::hasListeners('eloquent.created: '.SupportMessage::class)) {
        (new SupportInboxServiceProvider(app()))->boot();
    }
}

test('the inbox lists every store\'s messages, pending first, with counters and store context', function () {
    $admin = ad16SuperAdmin();
    [$ownerA] = createBusinessOwner();
    [$ownerB] = createBusinessOwner();

    $storeA = ad16Store($ownerA, ['name' => 'Ikeja Branch']);
    $storeB = ad16Store($ownerB, ['name' => 'Lekki Branch']);

    ad16Message($storeA, ['name' => 'Old Pending', 'created_at' => now()->subDays(3)]);
    ad16Message($storeA, ['name' => 'New Pending', 'created_at' => now()->subHour()]);
    ad16Message($storeB, [
        'name' => 'Answered',
        'status' => 'replied',
        'reply' => 'It ships today.',
        'replied_by_type' => 'business',
        'replied_by_id' => $ownerB->id,
        'replied_at' => now(),
        'created_at' => now()->subDay(),
    ]);
    ad16Message($storeB, ['name' => 'Closed One', 'status' => 'closed', 'created_at' => now()->subDays(5)]);

    $response = ad16Get($this, $admin, 'support-messages');

    $response->assertOk()
        // Pending first (newest pending at the top), then answered, then closed.
        ->assertJsonPath('data.messages.0.name', 'New Pending')
        ->assertJsonPath('data.messages.1.name', 'Old Pending')
        ->assertJsonPath('data.messages.2.name', 'Answered')
        ->assertJsonPath('data.messages.3.name', 'Closed One')
        // Counters are platform-wide, not scoped to the current page/filters.
        ->assertJsonPath('data.counts.pending', 2)
        ->assertJsonPath('data.counts.replied', 1)
        ->assertJsonPath('data.counts.closed', 1)
        ->assertJsonPath('data.counts.total', 4)
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('data.messages.2.replied_by_type_label', 'Business')
        ->assertJsonPath('data.messages.2.replied_by_name', $ownerB->name)
        ->assertJsonPath('data.messages.0.store.name', 'Ikeja Branch')
        ->assertJsonStructure([
            'data' => [
                'messages' => [[
                    'id', 'name', 'email', 'phone', 'message', 'status', 'reply',
                    'replied_by_type', 'replied_by_type_label', 'replied_at',
                    'created_at', 'store', 'can',
                ]],
                'stores',
                'counts',
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    expect(array_column($response->json('data.stores'), 'name'))
        ->toBe(['Ikeja Branch', 'Lekki Branch']);
});

test('search, status, store and date filters combine, and the filters validate', function () {
    $admin = ad16SuperAdmin();
    [$ownerA] = createBusinessOwner();
    [$ownerB] = createBusinessOwner();

    $storeA = ad16Store($ownerA, ['name' => 'Filter Store A']);
    $storeB = ad16Store($ownerB, ['name' => 'Filter Store B']);

    ad16Message($storeA, ['name' => 'Ada Customer', 'email' => 'ada@example.com', 'message' => 'Broken delivery']);
    ad16Message($storeA, ['name' => 'Bola Buyer', 'email' => 'bola@example.com', 'status' => 'replied', 'reply' => 'Refund issued.', 'replied_at' => now(), 'replied_by_type' => 'admin', 'replied_by_id' => $admin->id, 'created_at' => now()->subDays(10)]);
    ad16Message($storeB, ['name' => 'Chidi Client', 'email' => 'chidi@example.com', 'phone' => '08999999999']);

    // Free-text search covers name, email, phone, message and reply.
    expect(ad16Get($this, $admin, 'support-messages?q=Bola')->json('data.messages.0.name'))->toBe('Bola Buyer');
    expect(ad16Get($this, $admin, 'support-messages?q=chidi@example.com')->json('data.messages.0.name'))->toBe('Chidi Client');
    expect(ad16Get($this, $admin, 'support-messages?q=08999999')->json('data.messages.0.name'))->toBe('Chidi Client');
    expect(ad16Get($this, $admin, 'support-messages?q=Refund')->json('data.messages.0.name'))->toBe('Bola Buyer');

    // Status + store combine.
    $filtered = ad16Get($this, $admin, 'support-messages?status=replied&store_id='.$storeA->id);
    $filtered->assertOk();
    expect(array_column($filtered->json('data.messages'), 'name'))->toBe(['Bola Buyer']);

    // Date range: only the 10-day-old message.
    $ranged = ad16Get($this, $admin, 'support-messages?from='.now()->subDays(12)->toDateString().'&to='.now()->subDays(8)->toDateString());
    $ranged->assertOk();
    expect(array_column($ranged->json('data.messages'), 'name'))->toBe(['Bola Buyer']);

    // Bad filter values are rejected, not ignored.
    ad16Get($this, $admin, 'support-messages?status=archived')
        ->assertStatus(422)->assertJsonValidationErrors('status');
    ad16Get($this, $admin, 'support-messages?store_id=999999')
        ->assertStatus(422)->assertJsonValidationErrors('store_id');
});

test('the inbox paginates and keeps messages from soft-deleted stores visible with their status', function () {
    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $store = ad16Store($owner);
    $deleted = ad16Store($owner, ['name' => 'Gone Store', 'status' => Store::STATUS_DELETED]);

    foreach (range(1, 25) as $index) {
        ad16Message($store, ['name' => 'Customer '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'created_at' => now()->subMinutes($index)]);
    }

    // Deliberate departure from the legacy table dump: 10 a page.
    $page = ad16Get($this, $admin, 'support-messages?per_page=10');
    $page->assertOk()
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.total', 25);
    expect($page->json('data.messages'))->toHaveCount(10);

    $last = ad16Get($this, $admin, 'support-messages?per_page=10&page=3');
    $last->assertOk()->assertJsonPath('meta.current_page', 3);
    expect($last->json('data.messages'))->toHaveCount(5);

    // A message tied to a soft-deleted store stays visible — the customer is
    // still owed an answer — with the store's status exposed so the UI can say so.
    ad16Message($deleted, ['name' => 'Still Owed']);
    $withDeleted = ad16Get($this, $admin, 'support-messages?q=Still');
    $withDeleted->assertOk()
        ->assertJsonPath('data.messages.0.store.name', 'Gone Store')
        ->assertJsonPath('data.messages.0.store.status', Store::STATUS_DELETED);
});

test('the detail returns the full thread with the replier label and name', function () {
    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $store = ad16Store($owner, ['name' => 'Detail Store']);

    $answeredByAdmin = ad16Message($store, [
        'name' => 'Ada Customer',
        'status' => 'replied',
        'reply' => 'We are on it.',
        'replied_by_type' => 'admin',
        'replied_by_id' => $admin->id,
        'replied_at' => now(),
    ]);
    $answeredByBusiness = ad16Message($store, [
        'name' => 'Bola Buyer',
        'status' => 'replied',
        'reply' => 'Shipped this morning.',
        'replied_by_type' => 'business',
        'replied_by_id' => $owner->id,
        'replied_at' => now(),
    ]);
    $pending = ad16Message($store, ['name' => 'Chidi Client']);

    ad16Get($this, $admin, 'support-messages/'.$answeredByAdmin->id)
        ->assertOk()
        ->assertJsonPath('data.message.message', 'Where is my order?')
        ->assertJsonPath('data.message.replied_by_type_label', 'Admin')
        ->assertJsonPath('data.message.replied_by_name', $admin->name)
        ->assertJsonPath('data.message.store.name', 'Detail Store')
        ->assertJsonPath('data.message.store.business.name', $owner->business->name)
        ->assertJsonPath('data.message.store.business.business_code', $owner->business->business_code)
        ->assertJsonPath('data.message.can.reply', false)
        ->assertJsonPath('data.message.can.close', true);

    ad16Get($this, $admin, 'support-messages/'.$answeredByBusiness->id)
        ->assertOk()
        ->assertJsonPath('data.message.replied_by_type_label', 'Business')
        ->assertJsonPath('data.message.replied_by_name', $owner->name);

    ad16Get($this, $admin, 'support-messages/'.$pending->id)
        ->assertOk()
        ->assertJsonPath('data.message.replied_by_type_label', null)
        ->assertJsonPath('data.message.can.reply', true)
        ->assertJsonPath('data.message.can.reopen', false);

    ad16Get($this, $admin, 'support-messages/999999')->assertStatus(404);
});

test('a reply is validated, flips the status, emails the customer and is audited', function () {
    Mail::fake();

    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $message = ad16Message(ad16Store($owner));

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => ''])
        ->assertStatus(422)->assertJsonValidationErrors('reply');

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => str_repeat('a', 2001)])
        ->assertStatus(422)->assertJsonValidationErrors('reply');

    $response = ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', [
        'reply' => 'It ships today — apologies for the wait.',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.message.status', 'replied')
        ->assertJsonPath('data.message.replied_by_type', 'admin')
        ->assertJsonPath('data.message.replied_by_id', $admin->id)
        ->assertJsonPath('data.message.replied_by_type_label', 'Admin')
        ->assertJsonPath('data.email_queued', true);

    $message->refresh();
    expect($message->status)->toBe('replied')
        ->and($message->reply)->toBe('It ships today — apologies for the wait.')
        ->and($message->replied_at)->not->toBeNull();

    Mail::assertQueued(SupportMessageReplyMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));

    $entry = ActivityLog::query()->where('action', 'support_message_replied')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and((int) $entry->subject_id)->toBe($message->id)
        ->and($entry->old_values['status'])->toBe('pending')
        ->and($entry->new_values['status'])->toBe('replied');
});

test('an answered message refuses a silent re-reply until it is re-opened', function () {
    Mail::fake();

    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $message = ad16Message(ad16Store($owner));

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => 'First answer.'])
        ->assertOk();

    // Legacy defect #16: the second POST silently overwrote the first reply.
    $refused = ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => 'Sneaky overwrite.']);
    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('already been replied to');
    expect($message->fresh()->reply)->toBe('First answer.');

    // A deliberate re-open makes the follow-up legal.
    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reopen')
        ->assertOk()
        ->assertJsonPath('data.message.status', 'pending')
        ->assertJsonPath('data.message.reply', 'First answer.');

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => 'Follow-up answer.'])
        ->assertOk();

    expect($message->fresh()->reply)->toBe('Follow-up answer.');

    // The overwritten text still exists in the audit trail.
    $entry = ActivityLog::query()->where('action', 'support_message_replied')->latest('id')->first();
    expect($entry->old_values['reply'])->toBe('First answer.')
        ->and($entry->new_values['reply'])->toBe('Follow-up answer.');
});

test('close and re-open move the closed enum deliberately', function () {
    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $message = ad16Message(ad16Store($owner));

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/close')
        ->assertOk()
        ->assertJsonPath('data.message.status', 'closed')
        ->assertJsonPath('data.message.can.reply', false)
        ->assertJsonPath('data.message.can.close', false)
        ->assertJsonPath('data.message.can.reopen', true);

    // A closed conversation refuses new replies (both apps agree).
    $refused = ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => 'Too late?']);
    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('closed');

    // Idempotent repeats surface the state instead of silently no-oping.
    ad16Post($this, $admin, 'support-messages/'.$message->id.'/close')->assertStatus(422);

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reopen')
        ->assertOk()
        ->assertJsonPath('data.message.status', 'pending');

    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reopen')->assertStatus(422);

    // Replied threads can be closed or re-opened too.
    ad16Post($this, $admin, 'support-messages/'.$message->id.'/reply', ['reply' => 'Answered now.'])->assertOk();
    ad16Post($this, $admin, 'support-messages/'.$message->id.'/close')->assertOk();

    expect(ActivityLog::where('action', 'support_message_closed')->count())->toBe(2)
        ->and(ActivityLog::where('action', 'support_message_reopened')->count())->toBe(1);
});

test('deleting a message removes the row and records what was deleted', function () {
    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $message = ad16Message(ad16Store($owner), ['name' => 'Deletable', 'message' => 'Long complaint.']);

    ad16Delete($this, $admin, 'support-messages/'.$message->id)
        ->assertOk()
        ->assertJsonPath('message', 'Support message deleted successfully.');

    $this->assertDatabaseMissing('support_messages', ['id' => $message->id]);

    $entry = ActivityLog::query()->where('action', 'support_message_deleted')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->metadata['support_message_id'])->toBe($message->id)
        ->and($entry->metadata['customer_email'])->toBe('ada@example.com')
        ->and($entry->old_values['status'])->toBe('pending');

    ad16Delete($this, $admin, 'support-messages/'.$message->id)->assertStatus(404);
});

test('the stats endpoint counts by status for the page chips and the nav badge', function () {
    $admin = ad16SuperAdmin();
    [$owner] = createBusinessOwner();
    $store = ad16Store($owner);

    ad16Message($store, ['status' => 'pending']);
    ad16Message($store, ['status' => 'pending']);
    ad16Message($store, ['status' => 'replied', 'reply' => 'Done', 'replied_at' => now(), 'replied_by_type' => 'admin', 'replied_by_id' => $admin->id]);
    ad16Message($store, ['status' => 'closed']);

    ad16Get($this, $admin, 'support-messages/stats')
        ->assertOk()
        ->assertJsonPath('data.counts.pending', 2)
        ->assertJsonPath('data.counts.replied', 1)
        ->assertJsonPath('data.counts.closed', 1)
        ->assertJsonPath('data.counts.total', 4);
});

test('only platform accounts can reach the support inbox', function () {
    // A business-scoped owner: its in-business "Super Admin" role carries the
    // full permission bundle, admin.* names included, so the platform guard —
    // not the route permission — is what stops a leaked admin-audience token.
    [$owner, $business] = createBusinessOwner();
    setPermissionsTeamId($business->id);
    expect($owner->fresh()->can('admin.support'))->toBeTrue();

    [$otherOwner] = createBusinessOwner();
    $message = ad16Message(ad16Store($otherOwner));

    $this->getJson('/api/v1/admin/support-messages', [
        'Authorization' => 'Bearer '.ad16Token($owner),
    ])->assertStatus(403);

    $this->postJson('/api/v1/admin/support-messages/'.$message->id.'/reply', ['reply' => 'Cross-tenant.'], [
        'Authorization' => 'Bearer '.ad16Token($owner),
    ])->assertStatus(403);

    // A management token cannot cross audiences.
    $this->getJson('/api/v1/admin/support-messages', [
        'Authorization' => 'Bearer '.ad16Token($owner, 'management'),
    ])->assertStatus(403);

    // A platform account without the permission is refused by the route gate.
    $permissionless = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
    ad16Get($this, $permissionless, 'support-messages')->assertStatus(403);

    // Support Admin carries admin.support; Finance Admin does not.
    $support = ad16AdminWithRole('Support Admin');
    ad16Get($this, $support, 'support-messages')->assertOk();
    ad16Get($this, $support, 'support-messages/stats')->assertOk();

    $finance = ad16AdminWithRole('Finance Admin');
    ad16Get($this, $finance, 'support-messages')->assertStatus(403);

    $this->getJson('/api/v1/admin/support-messages')->assertStatus(401);
});

test('a new storefront message notifies the platform office and nobody else', function () {
    Mail::fake();
    ad16EnsureSupportObserverRegistered();

    $superadmin = ad16SuperAdmin();
    $platformAdmin = ad16AdminWithRole('Support Admin');
    $suspended = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'suspended',
        'is_verified' => true,
        'business_id' => null,
    ]);

    [$owner] = createBusinessOwner();
    $store = ad16Store($owner, ['support_email' => 'store-inbox@example.com']);

    // The storefront path: a plain model creation (the observer is the hook).
    ad16Message($store, ['name' => 'Fresh Customer']);

    Mail::assertQueued(AdminNewSupportMessageMail::class, function ($mail) use ($superadmin, $platformAdmin, $suspended) {
        return $mail->hasTo($superadmin->email)
            && $mail->hasTo($platformAdmin->email)
            && ! $mail->hasTo($suspended->email);
    });
});

test('with no platform office accounts a new message queues nothing', function () {
    Mail::fake();
    ad16EnsureSupportObserverRegistered();

    [$owner] = createBusinessOwner();
    ad16Message(ad16Store($owner));

    Mail::assertNothingQueued();
});

test('opening the support inbox is itself audited', function () {
    $admin = ad16SuperAdmin();

    ad16Get($this, $admin, 'support-messages')->assertOk();

    $entry = ActivityLog::query()->where('action', 'admin_route_accessed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($admin->id)
        ->and($entry->metadata['route'] ?? null)->toBe('api.admin.support-messages.index');
});
