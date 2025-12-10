<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\LiveFirstStatus;
use App\Mail\LiveFirstKycApprovedMail;
use App\Models\LiveFirstApplication;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class LiveFirstController extends Controller
{
    /**
     * Display list of all Live First applications
     */
    public function index(Request $request): View
    {
        $status = $request->query('status');
        
        $query = LiveFirstApplication::query()
            ->with(['user', 'store', 'reviewer'])
            ->latest('submitted_at')
            ->latest();

        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $applications = $query->paginate(20)->withQueryString();

        // Get status counts for filter tabs
        $statusCounts = LiveFirstApplication::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.live_first.index', [
            'applications' => $applications,
            'statusCounts' => $statusCounts,
            'currentStatus' => $status,
        ]);
    }

    /**
     * Show detailed view of a specific application
     */
    public function show(LiveFirstApplication $application): View
    {
        $application->load(['user', 'store', 'reviewer', 'documents']);

        return view('admin.live_first.show', [
            'application' => $application,
            'isActionable' => $application->status === 'pending',
        ]);
    }

    /**
     * Approve a Live First application
     */
    public function approve(Request $request, LiveFirstApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($application->status !== 'pending') {
            return redirect()->back()
                ->with('error', 'Only pending applications can be approved.');
        }

        try {
            DB::transaction(function () use ($application, $data) {
                // Update application status
                $application->update([
                    'status' => 'approved',
                    'rejection_reason' => null,
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);

                // Update user's Live First status to VERIFIED
                $application->user->update([
                    'live_first_status' => LiveFirstStatus::VERIFIED->value,
                ]);

                Log::info('admin.live_first.approved', [
                    'application_id' => $application->id,
                    'user_id' => $application->user_id,
                    'reviewer_id' => auth()->id(),
                ]);
            });

            // Send approval email to customer (queued)
            try {
                Mail::to($application->user->email)->queue(new LiveFirstKycApprovedMail($application));
                Log::info('live_first.approval_email_queued', [
                    'application_id' => $application->id,
                    'customer_email' => $application->user->email,
                ]);
            } catch (\Exception $e) {
                Log::error('live_first.approval_email_failed', [
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('admin.live-first.show', $application)
                ->with('success', 'Application approved successfully. User can now start the 6-month testing period.');
                
        } catch (\Exception $e) {
            Log::error('admin.live_first.approve_failed', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()->back()
                ->with('error', 'An error occurred while approving the application.');
        }
    }

    /**
     * Reject a Live First application
     */
    public function reject(Request $request, LiveFirstApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($application->status !== 'pending') {
            return redirect()->back()
                ->with('error', 'Only pending applications can be rejected.');
        }

        try {
            DB::transaction(function () use ($application, $data) {
                // Update application status
                $application->update([
                    'status' => 'rejected',
                    'rejection_reason' => $data['rejection_reason'],
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);

                // User status remains as pending_verification
                // They can view the rejection reason and re-apply if needed

                Log::info('admin.live_first.rejected', [
                    'application_id' => $application->id,
                    'user_id' => $application->user_id,
                    'reviewer_id' => auth()->id(),
                ]);
            });

            return redirect()->route('admin.live-first.show', $application)
                ->with('warning', 'Application rejected. User has been notified.');
                
        } catch (\Exception $e) {
            Log::error('admin.live_first.reject_failed', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()->back()
                ->with('error', 'An error occurred while rejecting the application.');
        }
    }

    /**
     * Toggle document verification status
     */
    public function toggleDocumentVerification(Request $request, LiveFirstApplication $application, $documentId): RedirectResponse
    {
        $document = $application->documents()->findOrFail($documentId);
        
        $document->update([
            'verified' => !$document->verified,
        ]);

        Log::info('admin.live_first.document_toggled', [
            'application_id' => $application->id,
            'document_id' => $documentId,
            'verified' => $document->verified,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->back()
            ->with('success', 'Document verification status updated.');
    }
}
