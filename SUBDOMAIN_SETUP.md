# Subdomain-Based Store Routing Setup

## Overview
This application now uses subdomain-based routing for vendor stores instead of path-based routing.

**Before:** `https://storify.test/honey-cakes/products`  
**After:** `https://honey-cakes.storify.test/products`

## Configuration Required

### 1. Environment Variables (.env)

Add or update the following in your `.env` file:

```env
APP_URL=https://storify.ng
APP_MAIN_DOMAIN=storify.ng

# Session configuration for subdomain support
SESSION_DOMAIN=.storify.ng
SESSION_SECURE_COOKIE=true
```

**Important Notes:**
- `APP_MAIN_DOMAIN` should be your root domain without protocol
- `SESSION_DOMAIN` should start with a dot (`.`) to enable session sharing across subdomains
- `SESSION_SECURE_COOKIE=true` is required for production with HTTPS

### 2. Cloudflare DNS Configuration

You've already set up wildcard DNS. Verify it's configured correctly:

1. Go to Cloudflare Dashboard → DNS
2. Ensure you have a record like:
   ```
   Type: CNAME or A
   Name: *
   Content: [Your Railway deployment URL or IP]
   Proxy: Enabled (Orange Cloud)
   ```

### 3. SSL Certificate

Since you have wildcard DNS configured in Cloudflare, SSL certificates will be automatically provisioned for all subdomains via Cloudflare's Universal SSL.

## Deployment Steps

### Step 1: Update Composer Autoloader

Run this command to register the new helper functions:

```bash
composer dump-autoload
```

### Step 2: Clear Caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Step 3: Deploy to Railway

Push your changes to Railway:

```bash
git add .
git commit -m "Implement subdomain-based store routing"
git push origin main
```

Railway will automatically deploy the changes.

### Step 4: Update Environment Variables on Railway

In Railway dashboard:
1. Go to your project → Variables
2. Add/Update:
   - `APP_URL=https://storify.ng`
   - `APP_MAIN_DOMAIN=storify.ng`
   - `SESSION_DOMAIN=.storify.ng`
   - `SESSION_SECURE_COOKIE=true`

### Step 5: Restart Application

In Railway, trigger a redeploy or restart the service.

## Testing

Test the subdomain routing:

1. **Main Domain:** Visit `https://storify.ng` → Should show homepage
2. **Store Subdomain:** Visit `https://honey-cakes.storify.ng` → Should show Honey Cakes store
3. **Product Page:** Visit `https://honey-cakes.storify.ng/products/[product-slug]-[code]`
4. **Cross-subdomain:** Test that sessions work across subdomains (if needed)

## How It Works

### 1. Route Definition (`routes/v1/home.php`)

```php
// Main domain routes
Route::domain(config('app.main_domain'))->group(function () {
    Route::get('/', [HomePageController::class, 'index']);
    // Other main domain routes...
});

// Subdomain routes for stores
Route::domain('{store_subdomain}.' . config('app.main_domain'))
    ->where(['store_subdomain' => '[A-Za-z0-9_\-]+'])
    ->group(function () {
        Route::get('/', [ProductController::class, 'indexByStore']);
        Route::get('/products/{slug}-{code}', [ProductController::class, 'show']);
        // Other store routes...
    });
```

### 2. Controller Updates

The `ProductController` now retrieves the store from the subdomain parameter:

```php
public function indexByStore(Request $request, string $store_subdomain = null)
{
    $storeSlug = $store_subdomain ?? $request->route('store_slug');
    $store = Store::where('slug', $storeSlug)->firstOrFail();
    // ...
}
```

### 3. URL Helpers

New helper functions for generating subdomain URLs:

```php
// In views
store_url($storeSlug) // Returns: https://honey-cakes.storify.ng
store_url($storeSlug, '/cart') // Returns: https://honey-cakes.storify.ng/cart
```

## Troubleshooting

### Issue: 404 on Subdomains
- **Cause:** Routes not cleared or environment variables not set
- **Fix:** Run `php artisan route:clear` and `php artisan config:clear`

### Issue: Sessions Not Working
- **Cause:** `SESSION_DOMAIN` not set correctly
- **Fix:** Ensure `SESSION_DOMAIN=.storify.ng` (note the leading dot)

### Issue: SSL Certificate Warnings
- **Cause:** Cloudflare proxy not enabled or wildcard DNS not configured
- **Fix:** Enable orange cloud (proxy) in Cloudflare DNS settings

### Issue: Old URLs Still in Use
- **Cause:** Cached views or routes
- **Fix:** Run `php artisan view:clear` and clear browser cache

## Migration Path

The implementation maintains backward compatibility. Old path-based URLs will redirect to new subdomain-based URLs automatically via the controller logic.

## Additional Notes

- The session domain configuration ensures shopping carts and authentication work across subdomains
- All internal links in views have been updated to use the new `store_url()` helper
- The `ProductController` handles both old and new URL formats during transition
