<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Mail\AdminNewSupportMessageMail;
use App\Mail\SupportMessageReceivedMail;
use App\Models\Store;
use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class StoreSupportController extends Controller
{
    /**
     * Display the store support page
     */
    public function index(string $store_slug)
    {
        $store = Store::where('slug', $store_slug)->whereNotIn('status', ['deleted'])->firstOrFail();

        // Check if store is pending - show pending page
        if ($store->status === 'pending') {
            $services = collect();
            try {
                $services = \DB::table('services')
                    ->where('status', 'active')
                    ->orderBy('position')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(['title', 'page_link']);
            } catch (\Throwable $e) {
                Log::warning('services_fetch_failed', ['error' => $e->getMessage()]);
            }

            return view('home.pages.management.store-pending', compact('store', 'services'));
        }

        // Only show support form for active stores
        if ($store->status !== 'active') {
            abort(404);
        }

        return view('storefront.pages.support', compact('store'));
    }

    /**
     * Store a new support message for a store
     */
    public function store(Request $request, string $store_slug)
    {
        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $supportMessage = SupportMessage::create([
                'store_id' => $store->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'message' => $request->message,
                'status' => 'pending',
            ]);

            Log::info('store.support.message.created', [
                'message_id' => $supportMessage->id,
                'store_id' => $store->id,
                'customer_email' => $request->email,
            ]);

            // Send confirmation email to customer
            try {
                Mail::to($request->email)->queue(new SupportMessageReceivedMail($supportMessage));
                Log::info('store.support.message.customer_email_queued', [
                    'message_id' => $supportMessage->id,
                ]);
            } catch (\Exception $e) {
                Log::error('store.support.message.customer_email_failed', [
                    'message_id' => $supportMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send notification to admin/company AND store owner if applicable
            // For now, mirroring existing logic which sends to admin
            try {
                $adminEmail = $store->support_email ?? config('mail.from.address');
                if ($adminEmail) {
                    Mail::to($adminEmail)->queue(new AdminNewSupportMessageMail($supportMessage));
                    Log::info('store.support.message.admin_email_queued', [
                        'message_id' => $supportMessage->id,
                        'email' => $adminEmail
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('store.support.message.admin_email_failed', [
                    'message_id' => $supportMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->back()
                ->with('success', 'Thank you! Your message has been received.');

        } catch (\Exception $e) {
            Log::error('store.support.message.creation_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'An error occurred while sending your message. Please try again.')
                ->withInput();
        }
    }
}
