<?php

use App\Http\Controllers\Api\V1\Management\ImpersonationStopController;

/*
|--------------------------------------------------------------------------
| AD-08 — impersonation stop with the legacy audit row (WS8)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth / audience / team
| group, so the "management" prefix, the "api.management." name and the
| middleware are inherited. Only Route:: lines belong in this file.
|
| `auth/stop-impersonation` is already registered by routes/api/v1/auth.php
| (shared, not editable) against Api\V1\Auth\ImpersonationController@stop: it
| ends the session and returns the admin pair, but never writes the legacy
| `user_impersonation_stopped` ActivityLog row WS-8 requires. Re-registering
| the same method+URI here replaces it in the route collection (api.php
| requires management.php after auth.php), so `route:list` keeps one entry
| with the same route name while ImpersonationStopController adds the row and
| preserves the response contract the management SPA consumes.
*/

Route::post('auth/stop-impersonation', [ImpersonationStopController::class, 'stop'])
    ->name('auth.stop-impersonation');
