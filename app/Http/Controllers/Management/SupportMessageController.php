<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Mail\SupportMessageReplyMail;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SupportMessageController extends Controller
{


    /**
     * Display support messages for vendor's store
     */
    public function index(Request $request)
    {
        $vendor = $request->user();

        // Get all store IDs that belong to this vendor
        $storeIds = \App\Models\Store::where('user_id', $vendor->id)->pluck('id');

        $messages = SupportMessage::whereIn('store_id', $storeIds)
            ->with('store')
            ->orderBy('status', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('management.support-messages.index', compact('vendor', 'messages'));
    }

    /**
     * Reply to a support message
     */
    public function reply(Request $request, SupportMessage $supportMessage)
    {
        $vendor = $request->user();

        // Ensure message belongs to one of vendor's stores
        $storeIds = \App\Models\Store::where('user_id', $vendor->id)->pluck('id');
        if (!$storeIds->contains($supportMessage->store_id)) {
            abort(403, 'Unauthorized action.');
        }

        $validator = Validator::make($request->all(), [
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator);
        }

        try {
            $supportMessage->update([
                'reply' => $request->reply,
                'status' => 'replied',
                'replied_by_type' => 'vendor',
                'replied_by_id' => $vendor->id,
                'replied_at' => now(),
            ]);

            Log::info('support.message.replied_by_vendor', [
                'message_id' => $supportMessage->id,
                'user_id' => $vendor->id,
            ]);

            // Send reply email to customer
            try {
                Mail::to($supportMessage->email)->queue(new SupportMessageReplyMail($supportMessage));
                Log::info('support.message.reply_email_queued', [
                    'message_id' => $supportMessage->id,
                ]);
            } catch (\Exception $e) {
                Log::error('support.message.reply_email_failed', [
                    'message_id' => $supportMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('management.support-messages.index', ['vendor' => $vendor])
                ->with('success', 'Reply sent successfully to the customer.');

        } catch (\Exception $e) {
            Log::error('support.message.reply_failed', [
                'message_id' => $supportMessage->id,
                'user_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to send reply. Please try again.');
        }
    }
}
