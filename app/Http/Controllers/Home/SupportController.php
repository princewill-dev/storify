<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Mail\AdminNewSupportMessageMail;
use App\Mail\SupportMessageReceivedMail;
use App\Models\Store;
use App\Models\SupportMessage;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Mail\SupportMessageAdmin;
use App\Mail\SupportMessageReceipt;

class SupportController extends Controller
{
    /**
     * Display the main platform support page
     */
    public function platformIndex()
    {
        return view('home.pages.support');
    }

    /**
     * Handle main platform support form submission
     */
    public function platformSend(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        try {
            // Get Company Email
            $setting = Setting::first();
            $adminEmail = $setting?->support_email ?? config('mail.from.address');

            // Send to Admin
            if ($adminEmail) {
                Mail::to($adminEmail)->send(new SupportMessageAdmin($validated));
            } else {
                Log::warning('Support email not configured. Message logged but not sent to admin.', $validated);
            }

            // Send Receipt to User
            Mail::to($validated['email'])->send(new SupportMessageReceipt($validated));

            Log::info('Support message sent successfully', ['email' => $validated['email'], 'subject' => $validated['subject']]);

            return response()->json([
                'success' => true,
                'message' => 'Your message has been received. We will get back to you shortly.'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send support message', [
                'error' => $e->getMessage(),
                'data' => $validated
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while sending your message. Please try again later.'
            ], 500);
        }
    }

    /**
     * Display the store support page (Original Logic)
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

        return view('home.pages.support.index', compact('store'));
    }

    /**
     * Store a new support message for a store (Original Logic)
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

            Log::info('support.message.created', [
                'message_id' => $supportMessage->id,
                'store_id' => $store->id,
                'customer_email' => $request->email,
            ]);

            // Send confirmation email to customer
            try {
                Mail::to($request->email)->queue(new SupportMessageReceivedMail($supportMessage));
                Log::info('support.message.customer_email_queued', [
                    'message_id' => $supportMessage->id,
                ]);
            } catch (\Exception $e) {
                Log::error('support.message.customer_email_failed', [
                    'message_id' => $supportMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send notification to admin/company
            try {
                $adminEmail = config('mail.from.address');
                if ($adminEmail) {
                    Mail::to($adminEmail)->queue(new AdminNewSupportMessageMail($supportMessage));
                    Log::info('support.message.admin_email_queued', [
                        'message_id' => $supportMessage->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('support.message.admin_email_failed', [
                    'message_id' => $supportMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->back()
                ->with('success', 'Thank you for contacting us! Your message has been received and we will respond within 24-48 hours.');

        } catch (\Exception $e) {
            Log::error('support.message.creation_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'An error occurred while sending your message. Please try again.')
                ->withInput();
        }
    }
}
