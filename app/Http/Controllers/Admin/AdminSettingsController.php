<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsUpdateRequest;
use App\Mail\SettingsUpdated;
use App\Models\Setting;
use App\Models\User;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use App\Models\Currency;

class AdminSettingsController extends Controller
{
    public function edit(): View
    {
        if (!auth()->check() || auth()->user()->role !== 'superadmin') {
            abort(403);
        }
        Log::info('settings_viewed', [
            'user_id' => auth()->id(),
            'role' => auth()->user()->role ?? null,
        ]);
        $settings = Setting::query()->first();
        $stores = Store::orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');
        $apiKeys = is_array($settings?->api_keys) ? $settings->api_keys : [];
        $certificatePath = $settings?->company_certificate_path;
        $certificateUrl = $certificatePath ? asset('storage/'.$certificatePath) : null;
        $certificateIsPdf = $certificatePath ? str_ends_with(strtolower($certificatePath), '.pdf') : false;
        $ogType = old('og_type', $settings->og_type ?? 'website');
        return view('admin.advanced.settings', compact('settings','stores','apiKeys','certificateUrl','certificateIsPdf','ogType','currencies','defaultCurrencyId'));
    }

    public function update(SettingsUpdateRequest $request): RedirectResponse
    {
        if (!auth()->check() || auth()->user()->role !== 'superadmin') {
            abort(403);
        }
        Log::info('settings_update_requested', [
            'user_id' => auth()->id(),
            'ip' => $request->ip(),
        ]);
        $settings = Setting::query()->first();
        $original = $settings ? $settings->toArray() : [];

        // Merge API key name/value pairs into associative array
        $apiKeys = null;
        if (is_array($request->input('api_key_names')) || is_array($request->input('api_key_values'))) {
            $names = $request->input('api_key_names', []);
            $values = $request->input('api_key_values', []);
            $apiKeys = [];
            foreach ($names as $idx => $n) {
                $key = trim((string)$n);
                $val = $values[$idx] ?? '';
                if ($key !== '' && $val !== '') {
                    $apiKeys[$key] = $val;
                }
            }
        } else {
            $apiKeys = $request->api_keys ? array_filter($request->api_keys, function ($v) { return $v !== null && $v !== ''; }) : null;
        }

        $data = [
            'company_name' => $request->company_name,
            'company_description' => $request->company_description,
            'support_email' => $request->support_email,
            'support_phone' => $request->support_phone,
            'company_address' => $request->company_address,
            'branch_address' => $request->branch_address,
            'api_keys' => $apiKeys,
            'main_store_id' => $request->main_store_id,
            // SEO
            'og_title' => $request->og_title,
            'og_description' => $request->og_description,
            'og_url' => $request->og_url,
            'og_type' => $request->og_type,
            // Greeting Modal
            'greeting_modal_enabled' => $request->has('greeting_modal_enabled'),
            'greeting_modal_frequency' => $request->greeting_modal_frequency ?? 'never',
        ];

        // Handle logo upload
        if ($request->hasFile('company_logo')) {
            if ($settings && $settings->company_logo_path) {
                try { Storage::disk('public')->delete($settings->company_logo_path); } catch (\Throwable $e) { Log::warning('logo_delete_failed', ['msg' => $e->getMessage()]); }
            }
            $path = $request->file('company_logo')->store('company', 'public');
            $data['company_logo_path'] = $path;
        }

        // Handle favicon upload
        if ($request->hasFile('company_favicon')) {
            if ($settings && $settings->company_favicon_path) {
                try { Storage::disk('public')->delete($settings->company_favicon_path); } catch (\Throwable $e) { Log::warning('favicon_delete_failed', ['msg' => $e->getMessage()]); }
            }
            $path = $request->file('company_favicon')->store('company', 'public');
            $data['company_favicon_path'] = $path;
        }

        // Handle certificate upload (pdf or image)
        if ($request->hasFile('company_certificate')) {
            if ($settings && $settings->company_certificate_path) {
                try { Storage::disk('public')->delete($settings->company_certificate_path); } catch (\Throwable $e) { Log::warning('certificate_delete_failed', ['msg' => $e->getMessage()]); }
            }
            $path = $request->file('company_certificate')->store('company', 'public');
            $data['company_certificate_path'] = $path;
        }

        // Handle OG image upload
        if ($request->hasFile('og_image')) {
            if ($settings && $settings->og_image_path) {
                try { Storage::disk('public')->delete($settings->og_image_path); } catch (\Throwable $e) { Log::warning('og_image_delete_failed', ['msg' => $e->getMessage()]); }
            }
            $path = $request->file('og_image')->store('company', 'public');
            $data['og_image_path'] = $path;
        }

        if (!$settings) {
            $settings = Setting::create($data);
        } else {
            $settings->update($data);
        }

        // Handle default currency selection
        if ($request->filled('default_currency_id')) {
            $cid = (int) $request->input('default_currency_id');
            try {
                Currency::where('is_default', true)->update(['is_default' => false]);
                Currency::where('id', $cid)->update(['is_default' => true]);
            } catch (\Throwable $e) {
                Log::error('currency_default_update_failed', ['msg' => $e->getMessage()]);
            }
        }

        // Compute changed fields diff (without logging sensitive values)
        $changed = [];
        foreach ($data as $key => $value) {
            $before = $original[$key] ?? null;
            if ($key === 'api_keys') {
                $beforeArr = is_string($before) ? json_decode($before, true) : ($before ?? []);
                $afterArr = $settings->api_keys ?? [];
                if ($beforeArr != $afterArr) {
                    $changed[$key] = [
                        'before' => array_keys($beforeArr ?? []),
                        'after' => array_keys($afterArr ?? []),
                    ];
                }
            } else if (($before ?? null) != ($settings->$key ?? null)) {
                // Do not include full addresses or emails in logs beyond indicating change
                $changed[$key] = true;
            }
        }

        Log::info('settings_updated', [
            'user_id' => auth()->id(),
            'changed_keys' => is_array($changed) ? array_keys(array_filter($changed, fn($v) => (bool)$v)) : [],
        ]);

        // Invalidate cached config pieces so UI reflects immediately
        try {
            Cache::forget('company_settings');
            if (array_key_exists('main_store_id', $data) && (($original['main_store_id'] ?? null) != ($settings->main_store_id ?? null))) {
                Cache::forget('admin_main_store');
            }
            // Invalidate on currency change as well
            if ($request->filled('default_currency_id')) {
                Cache::forget('company_settings');
            }
        } catch (\Throwable $e) {
            Log::warning('settings_cache_forget_failed', ['msg' => $e->getMessage()]);
        }

        try {
            // Send to superadmin via queue
            $superadmin = User::where('role', 'superadmin')->first();
            if ($superadmin && !empty($changed)) {
                Mail::to($superadmin->email)->queue(new SettingsUpdated($changed));
            }
        } catch (\Throwable $e) {
            Log::error('settings_update_mail_queue_failed', ['msg' => $e->getMessage()]);
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
