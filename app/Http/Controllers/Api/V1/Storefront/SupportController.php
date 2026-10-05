<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Mail\AdminNewSupportMessageMail;
use App\Mail\SupportMessageReceivedMail;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportController extends ApiController
{
    use ResolvesStorefrontContext;

    public function store(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $supportMessage = SupportMessage::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'message' => $validated['message'],
            'status' => 'pending',
        ]);

        Log::info('api.store.support_message.created', [
            'message_id' => $supportMessage->id,
            'store_id' => $store->id,
        ]);

        try {
            Mail::to($validated['email'])->queue(new SupportMessageReceivedMail($supportMessage));
        } catch (\Exception $e) {
            Log::error('api.store.support_message.customer_email_failed', ['error' => $e->getMessage()]);
        }

        try {
            $adminEmail = $store->support_email ?? config('mail.from.address');
            if ($adminEmail) {
                Mail::to($adminEmail)->queue(new AdminNewSupportMessageMail($supportMessage));
            }
        } catch (\Exception $e) {
            Log::error('api.store.support_message.admin_email_failed', ['error' => $e->getMessage()]);
        }

        return $this->ok([], 'Thank you! Your message has been received.');
    }
}
