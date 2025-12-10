<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SupportMessageReplyMail;
use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SupportMessageController extends Controller
{
    /**
     * Display all support messages
     */
    public function index()
    {
        $messages = SupportMessage::with('store')
            ->orderBy('status', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.support-messages.index', compact('messages'));
    }

    /**
     * Reply to a support message
     */
    public function reply(Request $request, SupportMessage $supportMessage)
    {
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
                'replied_by_type' => 'admin',
                'replied_by_id' => auth()->id(),
                'replied_at' => now(),
            ]);

            Log::info('support.message.replied', [
                'message_id' => $supportMessage->id,
                'admin_id' => auth()->id(),
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

            return redirect()->route('admin.support-messages.index')
                ->with('success', 'Reply sent successfully to the customer.');

        } catch (\Exception $e) {
            Log::error('support.message.reply_failed', [
                'message_id' => $supportMessage->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to send reply. Please try again.');
        }
    }

    /**
     * Delete a support message
     */
    public function destroy(SupportMessage $supportMessage)
    {
        try {
            $supportMessage->delete();

            Log::info('support.message.deleted', [
                'message_id' => $supportMessage->id,
                'admin_id' => auth()->id(),
            ]);

            return redirect()->route('admin.support-messages.index')
                ->with('success', 'Support message deleted successfully.');

        } catch (\Exception $e) {
            Log::error('support.message.delete_failed', [
                'message_id' => $supportMessage->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to delete message. Please try again.');
        }
    }
}
