<?php

namespace App\Services;

use App\Mail\AdminStoreCreated;
use App\Mail\StoreActivated;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class StoreActivationNotifier
{
    public function send(User $user): void
    {
        $store = $user->stores()->latest()->first();
        if (! $store) {
            return;
        }

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new StoreActivated($store));
            }

            $admins = User::where('role', User::ROLE_SUPERADMIN)->pluck('email')->filter()->all();
            if ($admins) {
                Mail::to($admins)->queue(new AdminStoreCreated($store));
            }
        } catch (\Throwable $e) {
            Log::error('store_activation_email_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
