<?php

use App\Enums\TransactionStatus;
use App\Mail\DigitalDownloadMail;
use App\Models\Customer;
use App\Models\DigitalDownload;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Digital download token flow — ported from
| tests/Feature/Digital/DigitalProductTest.php (legacy web routes)
|--------------------------------------------------------------------------
| The legacy file exercised the Blade download routes (downloads.show /
| downloads.file), which have no API equivalent: the API delivers the 64-char
| token through the account payloads but exposes no endpoint that redeems it
| for the file. The API-reachable half of the legacy behaviour is covered
| here: digital product uploads, digital-only checkout, and token issuance on
| both payment paths (storefront Paystack verification and management
| confirmation of an offline payment).
*/

function ddcToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ddcStore(User $owner, array $attributes = []): Store
{
    static $sequence = 0;

    return Store::create(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
        'name' => 'Digital Downloads Store '.++$sequence,
        'slug' => 'ddc-store-'.$sequence,
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ], $attributes));
}

function ddcProduct(Store $store, array $attributes = []): Product
{
    return Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'The E-Book',
        'amount' => 5000,
        'status' => 'active',
        'is_digital' => true,
        'quantity' => 0,
        'download_limit' => 5,
        'download_expiry_days' => 7,
    ], $attributes));
}

function ddcCustomer(int $businessId, array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'business_id' => $businessId,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'ddc-buyer-'.Str::lower(Str::random(8)).'@example.test',
        'phone' => '08000000000',
        'password' => bcrypt('secret-password'),
        'status' => 'active',
    ], $attributes));
}

/**
 * Fake the two Paystack reads doubleVerifyPayment() makes: transaction/verify
 * (single object) and transaction?reference= (list the second confirmation
 * scans). The reference is echoed from the URL so the controller's generated
 * value round-trips.
 */
function ddcFakePaystack(int $amountKobo = 500000): void
{
    Http::swap(new Factory);

    Http::fake(function (Request $request) use ($amountKobo) {
        $url = $request->url();

        if (str_contains($url, 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/ddc',
                    'access_code' => 'ddc_access',
                ],
            ]);
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $reference = Str::afterLast($path, '/');

        if (str_contains($url, 'transaction/verify')) {
            return Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'id' => 445566,
                    'status' => 'success',
                    'amount' => $amountKobo,
                    'currency' => 'NGN',
                    'reference' => $reference,
                ],
            ]);
        }

        // Second confirmation reads a list and matches on reference.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Http::response([
            'status' => true,
            'data' => [[
                'id' => 445566,
                'status' => 'success',
                'amount' => $amountKobo,
                'currency' => 'NGN',
                'reference' => $query['reference'] ?? $reference,
            ]],
        ]);
    });
}

test('a business owner can create a digital product with downloadable files through the api', function () {
    Storage::fake('local');

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ddcStore($owner);

    $response = $this->withToken(ddcToken($owner))->post('/api/v1/management/products', [
        'name' => 'My E-Book',
        'store_id' => $store->id,
        'amount' => 3500,
        'status' => 'active',
        'is_digital' => 1,
        'download_limit' => 3,
        'download_expiry_days' => 14,
        'digital_files' => [
            UploadedFile::fake()->create('ebook.pdf', 512, 'application/pdf'),
            UploadedFile::fake()->create('bonus.zip', 256, 'application/zip'),
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.product.is_digital', true)
        ->assertJsonPath('data.product.download_limit', 3)
        ->assertJsonPath('data.product.download_expiry_days', 14)
        ->assertJsonCount(2, 'data.product.files')
        ->assertJsonPath('data.product.files.0.original_name', 'ebook.pdf')
        ->assertJsonPath('data.product.files.0.exists_on_disk', true)
        ->assertJsonPath('data.product.files.1.original_name', 'bonus.zip');

    $product = Product::where('business_id', $owner->business_id)->where('name', 'My E-Book')->sole();

    expect($product->is_digital)->toBeTrue()
        ->and($product->downloadLimit())->toBe(3)
        ->and($product->downloadExpiryDays())->toBe(14)
        ->and($product->files)->toHaveCount(2)
        ->and($product->files->first()->original_name)->toBe('ebook.pdf');

    $first = ProductFile::where('product_id', $product->id)->orderBy('position')->firstOrFail();

    expect($first->disk)->toBe('local');
    Storage::disk('local')->assertExists($first->path);
});

test('a digital-only api checkout is tagged digital and writes no stock movement', function () {
    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ddcStore($owner);
    $product = ddcProduct($store, ['quantity' => 0]);

    $headers = ['X-Guest-Token' => 'ddc-guest-checkout'];

    $this->postJson('/api/v1/storefront/'.$store->slug.'/cart/items', [
        'product_id' => $product->id,
        'qty' => 1,
    ], $headers)->assertOk();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', [
        'email' => 'jane.ddc@example.test',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '08000000000',
    ], $headers)->assertCreated();

    $order = Order::where('store_id', $store->id)->sole();

    expect($order->source)->toBe('digital')
        ->and($order->items()->first()->is_digital)->toBeTrue()
        // Digital products have no stock to decrement.
        ->and($product->fresh()->quantity)->toBe(0)
        ->and(StockMovement::where('product_id', $product->id)->count())->toBe(0);
});

test('a verified storefront payment issues download tokens and queues the buyer email', function () {
    Mail::fake();
    ddcFakePaystack();

    [$owner] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ddcStore($owner);
    $product = ddcProduct($store);

    $headers = ['X-Guest-Token' => 'ddc-guest-payment'];

    $this->postJson('/api/v1/storefront/'.$store->slug.'/cart/items', [
        'product_id' => $product->id,
        'qty' => 1,
    ], $headers)->assertOk();

    $this->postJson('/api/v1/storefront/'.$store->slug.'/checkout', [
        'email' => 'jane.paid@example.test',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '08000000000',
    ], $headers)->assertCreated();

    $order = Order::where('store_id', $store->id)->sole();

    // Not paid yet — nothing generated.
    expect($order->digitalDownloads()->count())->toBe(0);

    $initialize = $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/initialize', [
        'order_number' => $order->order_number,
    ], $headers)->assertOk();

    $reference = $initialize->json('data.reference');

    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/verify', [
        'reference' => $reference,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.fully_paid', true)
        ->assertJsonPath('data.downloads.0.product_name', 'The E-Book')
        ->assertJsonPath('data.downloads.0.downloads_remaining', 5)
        ->assertJsonPath('data.downloads.0.status', 'Active');

    $download = DigitalDownload::where('order_id', $order->id)->sole();

    expect($download->max_downloads)->toBe(5)
        ->and($download->downloadsRemaining())->toBe(5)
        ->and($download->expires_at->isFuture())->toBeTrue()
        ->and(strlen($download->token))->toBe(64);

    Mail::assertQueued(DigitalDownloadMail::class);

    // A duplicate gateway callback must not mint a second token: the delivery
    // service skips order items that already have one. (The HTTP status of the
    // replay is deliberately not asserted so this covers token idempotency
    // regardless of how replay protection evolves.)
    $this->postJson('/api/v1/storefront/'.$store->slug.'/payments/paystack/verify', [
        'reference' => $reference,
    ], $headers);

    expect(DigitalDownload::where('order_id', $order->id)->count())->toBe(1);
});

test('confirming an offline payment for a digital order also issues its download tokens', function () {
    Mail::fake();

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    $store = ddcStore($owner);
    $product = ddcProduct($store);
    $customer = ddcCustomer($business->id, ['email' => 'jane.confirm@example.test']);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'digital',
        'order_number' => 'DDC-OFFLINE-1',
        'subtotal' => 5000,
        'total' => 5000,
        'amount_paid' => 0,
        'status' => 'pending',
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 5000,
        'quantity' => 1,
        'subtotal' => 5000,
        'is_digital' => true,
    ]);

    $transaction = Transaction::create([
        'reference' => 'DDC-OFFLINE-TXN-1',
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 5000,
        'currency' => 'NGN',
        'status' => TransactionStatus::PENDING,
        'paid_at' => now(),
    ]);

    $this->withToken(ddcToken($owner))
        ->postJson('/api/v1/management/transactions/'.$transaction->reference.'/confirm')
        ->assertOk()
        ->assertJsonPath('data.transaction.status', 'confirmed');

    $download = $order->fresh()->digitalDownloads()->sole();

    expect($download->max_downloads)->toBe(5)
        ->and($download->downloadsRemaining())->toBe(5)
        ->and($download->expires_at->isFuture())->toBeTrue()
        ->and(strlen($download->token))->toBe(64);

    Mail::assertQueued(DigitalDownloadMail::class);
});
