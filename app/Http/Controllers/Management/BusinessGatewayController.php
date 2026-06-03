<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\BusinessGateway;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BusinessGatewayController extends Controller
{
    public function __construct(protected PaystackService $paystackService) {}

    /**
     * Payment settings page — show gateways + bank accounts.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        if (!$user?->business_id) {
            return view('management.payment-settings.index', [
                'user' => $user,
                'gateways' => collect(),
                'stores' => collect(),
                'storeBanks' => collect(),
            ]);
        }

        $gateways = BusinessGateway::where('business_id', $user->business_id)
            ->orderBy('gateway')
            ->get();

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();
        $storeBanks = \App\Models\StoreBank::whereHas('store', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with('store')->get();

        $banks = $this->paystackService->getBanks()['data'] ?? [];

        return view('management.payment-settings.index', compact('user', 'gateways', 'stores', 'storeBanks', 'banks'));
    }

    /**
     * Store new gateway credentials.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'gateway' => 'required|in:paystack',
            'public_key' => 'required|string|max:5000',
            'secret_key' => 'required|string|max:5000',
        ]);

        $existing = BusinessGateway::where('business_id', $user->business_id)
            ->where('gateway', $validated['gateway'])
            ->first();

        if ($existing) {
            return back()->with('error', 'This gateway is already connected. Edit it instead.');
        }

        try {
            $gateway = BusinessGateway::create([
                'business_id' => $user->business_id,
                'gateway' => $validated['gateway'],
                'public_key' => $validated['public_key'],
                'secret_key' => $validated['secret_key'],
                'is_active' => true,
                'is_verified' => false,
            ]);

            Log::info('gateway.created', [
                'user_id' => $user->id,
                'gateway_id' => $gateway->id,
                'gateway' => $validated['gateway'],
            ]);

            return back()->with('success', 'Gateway connected. Test your keys to verify the connection.');
        } catch (\Throwable $e) {
            Log::error('gateway.create_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Failed to save gateway credentials.')->withInput();
        }
    }

    /**
     * Update gateway credentials.
     */
    public function update(Request $request, BusinessGateway $gateway): RedirectResponse
    {
        $user = $request->user();
        if ($gateway->business_id !== $user->business_id) {
            abort(403);
        }

        $validated = $request->validate([
            'public_key' => 'required|string|max:5000',
            'secret_key' => 'required|string|max:5000',
        ]);

        $gateway->update([
            'public_key' => $validated['public_key'],
            'secret_key' => $validated['secret_key'],
            'is_verified' => false,
        ]);

        Log::info('gateway.updated', ['user_id' => $user->id, 'gateway_id' => $gateway->id]);

        return back()->with('success', 'Gateway updated. Test your new keys to re-verify.');
    }

    /**
     * Toggle gateway active/inactive.
     */
    public function toggle(Request $request, BusinessGateway $gateway): RedirectResponse
    {
        $user = $request->user();
        if ($gateway->business_id !== $user->business_id) {
            abort(403);
        }

        $gateway->update(['is_active' => !$gateway->is_active]);

        Log::info('gateway.toggled', [
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'is_active' => $gateway->is_active,
        ]);

        return back()->with('success', 'Gateway ' . ($gateway->is_active ? 'activated' : 'deactivated') . '.');
    }

    /**
     * Remove a gateway configuration.
     */
    public function destroy(Request $request, BusinessGateway $gateway): RedirectResponse
    {
        $user = $request->user();
        if ($gateway->business_id !== $user->business_id) {
            abort(403);
        }

        $gateway->delete();

        Log::info('gateway.deleted', ['user_id' => $user->id, 'gateway_id' => $gateway->id]);

        return back()->with('success', 'Gateway removed.');
    }

    /**
     * Test gateway connection (AJAX).
     */
    public function test(Request $request, BusinessGateway $gateway): JsonResponse
    {
        $user = $request->user();
        if ($gateway->business_id !== $user->business_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        try {
            $result = $this->paystackService->usingGateway($gateway)->testConnection();

            if ($result['success']) {
                $gateway->update(['is_verified' => true]);

                Log::info('gateway.test_passed', [
                    'user_id' => $user->id,
                    'gateway_id' => $gateway->id,
                ]);
            } else {
                $gateway->update(['is_verified' => false]);
            }

            return response()->json($result);

        } catch (\Throwable $e) {
            Log::error('gateway.test_failed', [
                'user_id' => $user->id,
                'gateway_id' => $gateway->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-configure Paystack webhook.
     */
    public function configureWebhook(Request $request, BusinessGateway $gateway): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if ($gateway->business_id !== $user->business_id) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'Unauthorized.'], 403)
                : back()->with('error', 'Unauthorized.');
        }

        $webhookUrl = rtrim(config('app.url'), '/') . '/webhooks/paystack';

        try {
            $service = $this->paystackService->usingGateway($gateway);

            // First, remove any existing webhooks pointing to our URL
            $existing = $service->getWebhooks();
            if ($existing['success'] && !empty($existing['data'])) {
                foreach ($existing['data'] as $wh) {
                    if (($wh['url'] ?? '') === $webhookUrl) {
                        $service->deleteWebhook($wh['id']);
                    }
                }
            }

            // Create new webhook
            $result = $service->createWebhook($webhookUrl);

            if ($result['success']) {
                $webhookId = $result['data']['id'] ?? null;
                $gateway->update(['webhook_id' => $webhookId, 'is_verified' => true]);

                Log::info('gateway.webhook_configured', [
                    'user_id' => $user->id,
                    'gateway_id' => $gateway->id,
                    'webhook_id' => $webhookId,
                    'url' => $webhookUrl,
                ]);

                $message = 'Webhook configured! Paystack will send payment notifications to ' . $webhookUrl;

                return $request->wantsJson()
                    ? response()->json(['success' => true, 'message' => $message, 'webhook_id' => $webhookId])
                    : back()->with('success', $message);
            }

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $result['message']])
                : back()->with('error', $result['message']);

        } catch (\Throwable $e) {
            Log::error('gateway.webhook_failed', [
                'user_id' => $user->id,
                'gateway_id' => $gateway->id,
                'error' => $e->getMessage(),
            ]);

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()])
                : back()->with('error', 'Webhook setup failed: ' . $e->getMessage());
        }
    }
}
