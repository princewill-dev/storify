<?php

namespace App\Providers;

use App\Models\SupportMessage;
use App\Observers\SupportMessageObserver;
use Illuminate\Support\ServiceProvider;

/**
 * WS-16 — support inbox wiring.
 *
 * WIRING (orchestrator — mount in a shared file): add this provider to
 * bootstrap/providers.php:
 *
 *     App\Providers\SupportInboxServiceProvider::class,
 *
 * It exists as its own provider (rather than an edit to AppServiceProvider)
 * so this workstream never touches a shared file. It registers the observer
 * that notifies the platform office when a storefront support message lands.
 */
class SupportInboxServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        SupportMessage::observe(SupportMessageObserver::class);
    }
}
