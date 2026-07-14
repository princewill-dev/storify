#!/bin/bash

# 1. Recreate Laravel's required folder structure inside the empty volume
mkdir -p storage/framework/views
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/app/public

# 2. Clear out any old cached paths so it recognizes the new ones
php artisan optimize:clear

# 3. (Optional) Run your database migrations automatically on boot
php artisan migrate --force

# 4. Hand off the process to Railway's default start script (Caddy + PHP-FPM)
exec /assets/start.sh