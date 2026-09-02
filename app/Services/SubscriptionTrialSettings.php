<?php

namespace App\Services;

use App\Models\Setting;

final class SubscriptionTrialSettings
{
    public function get(): array
    {
        $settings = Setting::query()->first();

        return [
            'enabled' => $settings?->trial_enabled ?? true,
            'days' => (int) ($settings?->trial_days ?? 7),
        ];
    }
}
