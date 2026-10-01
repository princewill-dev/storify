<?php

use App\Mail\DigitalDownloadMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DigitalDownload;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Store;
use App\Services\Digital\DigitalDeliveryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

function digitalContext(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Digital Store',
        'slug' => 'digital-store',
        'status' => Store::STATUS_ACTIVE,
        'has_website' => true,
    ]);

    return [$owner, $business, $store];
}

function digitalProduct(Store $store, array $overrides = []): Product
{
    $product = Product::create(array_merge([
        'store_id' => $store->id,
        'business_id' => $store->business_id,
        'name' => 'The E-Book',
        'amount' => 5000,
        'status' => 'active',
        'is_digital' => true,
        'quantity' => 0,
    ], $overrides));

    ProductFile::create([
        'product_id' => $product->id,
        'business_id' => $store->business_id,
        'disk' => 'local',
        'path' => 'products/downloads/test/ebook.pdf',
        'original_name' => 'ebook.pdf',
        'mime_type' => 'application/pdf',
        'size' => 2048,
        'is_primary' => true,
        'position' => 0,
    ]);

    return $product;
}

function digitalCart(Store $store, Product $product, int $qty = 1): Cart
{
    $unitKobo = (int) round(((float) $product->amount) * 100);

    $cart = Cart::create([
        'store_id' => $store->id,
        'status' => 'active',
        'checkout_token' => 'test-checkout-token-'.uniqid(),
        'currency' => 'NGN',
    ]);

    CartItem::create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'unit_amount' => $unitKobo,
        'qty' => $qty,
        'line_subtotal' => $unitKobo * $qty,
    ]);

    $cart->recalcTotals();

    return $cart->fresh();
}

test('a business owner can upload a digital product with files', function () {
    Storage::fake('local');

    [$owner, $business, $store] = digitalContext();

    actingAs($owner)->post(route('management.products.store'), [
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
    ])->assertSessionHasNoErrors();

    $product = Product::where('business_id', $business->id)->where('name', 'My E-Book')->sole();

    expect($product->is_digital)->toBeTrue()
        ->and($product->downloadLimit())->toBe(3)
        ->and($product->downloadExpiryDays())->toBe(14)
        ->and($product->files)->toHaveCount(2)
        ->and($product->files->first()->original_name)->toBe('ebook.pdf');

    Storage::disk('local')->assertExists($product->files->first()->path);
});

test('a digital-only checkout skips shipping and stock', function () {
    Storage::fake('local');

    [, $business, $store] = digitalContext();
    $product = digitalProduct($store);
    $cart = digitalCart($store, $product);

    $order = app(\App\Actions\Checkout\PlaceStorefrontOrder::class)->execute(
        $store,
        null,
        [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.test',
            'phone' => '08000000000',
            'checkout_token' => $cart->checkout_token,
        ],
        null,
        '127.0.0.1',
    );

    expect((float) $order->shipping_fee)->toBe(0.0)
        ->and($order->delivery_address_id)->toBeNull()
        ->and($order->source)->toBe('digital')
        ->and((float) $order->total)->toBe(5000.0)
        ->and($order->items->first()->is_digital)->toBeTrue();

    // Digital products have no stock to decrement
    expect($product->fresh()->quantity)->toBe(0)
        ->and(\App\Models\StockMovement::where('product_id', $product->id)->count())->toBe(0);
});

test('paid digital orders generate download tokens and email the buyer', function () {
    Storage::fake('local');
    Mail::fake();

    [, $business, $store] = digitalContext();
    $product = digitalProduct($store, ['download_limit' => 5, 'download_expiry_days' => 7]);
    $cart = digitalCart($store, $product);

    $order = app(\App\Actions\Checkout\PlaceStorefrontOrder::class)->execute(
        $store,
        null,
        [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.test',
            'phone' => '08000000000',
            'checkout_token' => $cart->checkout_token,
        ],
        null,
        '127.0.0.1',
    );

    // Not paid yet — nothing generated
    expect($order->digitalDownloads()->count())->toBe(0);

    $order->update(['amount_paid' => $order->total]);

    $created = app(DigitalDeliveryService::class)->deliver($order->fresh());

    expect($created)->toBe(1);

    $download = DigitalDownload::where('order_id', $order->id)->sole();

    expect($download->max_downloads)->toBe(5)
        ->and($download->downloadsRemaining())->toBe(5)
        ->and($download->expires_at->isFuture())->toBeTrue()
        ->and(strlen($download->token))->toBe(64);

    Mail::assertQueued(DigitalDownloadMail::class);

    // Idempotent
    $again = app(DigitalDeliveryService::class)->deliver($order->fresh());
    expect($again)->toBe(0)
        ->and(DigitalDownload::where('order_id', $order->id)->count())->toBe(1);
});

test('a download token streams the file and increments the counter', function () {
    Storage::fake('local');

    [, $business, $store] = digitalContext();
    $product = digitalProduct($store);
    $file = $product->files->first();

    Storage::disk('local')->put($file->path, 'PDF-CONTENT');

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => null,
        'source' => 'digital',
        'order_number' => 'ORD-DIGITAL-1',
        'subtotal' => 5000,
        'total' => 5000,
        'amount_paid' => 5000,
        'status' => 'accepted',
    ]);

    $item = $order->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 5000,
        'quantity' => 1,
        'subtotal' => 5000,
        'is_digital' => true,
    ]);

    $download = DigitalDownload::create([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'product_id' => $product->id,
        'token' => str_repeat('a', 64),
        'download_count' => 0,
        'max_downloads' => 5,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('downloads.show', ['token' => $download->token]))->assertOk();

    $this->get(route('downloads.file', ['token' => $download->token, 'file' => $file->id]))
        ->assertOk()
        ->assertHeader('content-disposition');

    expect($download->fresh()->download_count)->toBe(1);
});

test('expired and exhausted download tokens are rejected', function () {
    Storage::fake('local');

    [, $business, $store] = digitalContext();
    $product = digitalProduct($store);
    $file = $product->files->first();

    Storage::disk('local')->put($file->path, 'PDF-CONTENT');

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'source' => 'digital',
        'order_number' => 'ORD-DIGITAL-2',
        'subtotal' => 5000,
        'total' => 5000,
        'amount_paid' => 5000,
        'status' => 'accepted',
    ]);

    $item = $order->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 5000,
        'quantity' => 1,
        'subtotal' => 5000,
        'is_digital' => true,
    ]);

    $expired = DigitalDownload::create([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'product_id' => $product->id,
        'token' => str_repeat('b', 64),
        'download_count' => 0,
        'max_downloads' => 5,
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('downloads.file', ['token' => $expired->token, 'file' => $file->id]))
        ->assertRedirect(route('downloads.show', ['token' => $expired->token]));

    expect($expired->fresh()->download_count)->toBe(0);

    $exhausted = DigitalDownload::create([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'order_item_id' => $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 5000,
            'quantity' => 1,
            'subtotal' => 5000,
            'is_digital' => true,
        ])->id,
        'product_id' => $product->id,
        'token' => str_repeat('c', 64),
        'download_count' => 5,
        'max_downloads' => 5,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('downloads.file', ['token' => $exhausted->token, 'file' => $file->id]))
        ->assertRedirect(route('downloads.show', ['token' => $exhausted->token]));

    expect($exhausted->fresh()->download_count)->toBe(5);
});

test('a customer sees their downloads in the account area', function () {
    Storage::fake('local');

    [, $business, $store] = digitalContext();
    $product = digitalProduct($store);

    $customer = \App\Models\Customer::create([
        'business_id' => $business->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.downloads@example.test',
        'phone' => '08000000000',
        'password' => bcrypt('secret-password'),
        'status' => 'active',
    ]);

    $order = Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'source' => 'digital',
        'order_number' => 'ORD-DIGITAL-3',
        'subtotal' => 5000,
        'total' => 5000,
        'amount_paid' => 5000,
        'status' => 'accepted',
    ]);

    $item = $order->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 5000,
        'quantity' => 1,
        'subtotal' => 5000,
        'is_digital' => true,
    ]);

    DigitalDownload::create([
        'business_id' => $business->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'product_id' => $product->id,
        'customer_id' => $customer->id,
        'token' => str_repeat('d', 64),
        'download_count' => 0,
        'max_downloads' => 5,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($customer, 'customer')
        ->get(route('account.downloads'))
        ->assertOk()
        ->assertSee('The E-Book');
});
