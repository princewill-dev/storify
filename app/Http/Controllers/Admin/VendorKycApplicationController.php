<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\KycApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class VendorKycApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $statusOptions = [
            KycApplication::STATUS_SUBMITTED => 'Submitted',
            KycApplication::STATUS_APPROVED => 'Approved',
            KycApplication::STATUS_REJECTED => 'Rejected',
        ];

        $query = KycApplication::query()
            ->with(['vendor'])
            ->latest('submitted_at')
            ->latest();

        if ($status && in_array($status, [
            KycApplication::STATUS_DRAFT,
            KycApplication::STATUS_SUBMITTED,
            KycApplication::STATUS_APPROVED,
            KycApplication::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        $applications = $query->paginate(20)->withQueryString();

        $statusCounts = KycApplication::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.vendors.kyc.index', [
            'applications' => $applications,
            'statusCounts' => $statusCounts,
            'status' => $status,
            'statusOptions' => $statusOptions,
            'statusBadgeData' => KycApplication::statusBadgeData(),
        ]);
    }

    public function show(VendorKycApplication $application): View
    {
        $application->load(['vendor', 'reviewer']);

        $badge = $application->status_metadata;
        return view('admin.vendors.kyc.show', [
            'application' => $application,
            'statusBadge' => $badge,
            'statusLabelLower' => strtolower($badge['label'] ?? ''),
            'isActionable' => $application->status === KycApplication::STATUS_SUBMITTED,
        ]);
    }

    public function approve(Request $request, VendorKycApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($application, $data) {
            $application->fill([
                'status' => KycApplication::STATUS_APPROVED,
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => auth()->id(),
                'approved_at' => now(),
                'rejected_at' => null,
            ])->save();

            $application->vendor->forceFill([
                'status' => Vendor::STATUS_ACTIVE,
            ])->save();
        });

        Log::info('admin.vendor_kyc.approved', [
            'application_id' => $application->id,
            'user_id' => $application->user_id,
            'reviewer_id' => auth()->id(),
        ]);

        return redirect()->route('admin.vendor-kyc.show', $application)
            ->with('success', 'KYC application approved successfully.');
    }

    public function reject(Request $request, VendorKycApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($application, $data) {
            $application->fill([
                'status' => KycApplication::STATUS_REJECTED,
                'review_notes' => $data['review_notes'],
                'reviewed_by' => auth()->id(),
                'approved_at' => null,
                'rejected_at' => now(),
            ])->save();

            $application->vendor->forceFill([
                'status' => Vendor::STATUS_PENDING,
            ])->save();
        });

        Log::info('admin.vendor_kyc.rejected', [
            'application_id' => $application->id,
            'user_id' => $application->user_id,
            'reviewer_id' => auth()->id(),
        ]);

        return redirect()->route('admin.vendor-kyc.show', $application)
            ->with('warning', 'KYC application rejected and vendor notified.');
    }
}
