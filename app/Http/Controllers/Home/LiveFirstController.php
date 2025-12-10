<?php

namespace App\Http\Controllers\Home;

use App\Enums\KycDocumentType;
use App\Enums\LiveFirstStatus;
use App\Http\Controllers\Controller;
use App\Mail\AdminLiveFirstKycSubmittedMail;
use App\Mail\LiveFirstKycReceivedMail;
use App\Models\LiveFirstApplication;
use App\Models\LiveFirstKycDocument;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LiveFirstController extends Controller
{
    /**
     * Show the Live First program intro page
     */
    public function index(Request $request, $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        
        $user = Auth::guard('customer')->user();
        $currentStatus = $user?->live_first_status ?? LiveFirstStatus::NOT_ENROLLED;
        $application = $user?->liveFirstApplication;
        
        return view('home.pages.live_first.index', compact('store', 'currentStatus', 'application'));
    }

    /**
     * Show the KYC application form
     */
    public function showKycForm(Request $request, $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $user = Auth::guard('customer')->user();
        
        // Check if user already has a pending or approved application
        $existingApplication = $user->liveFirstApplication;
        
        if ($existingApplication && $existingApplication->status !== 'rejected') {
            return redirect()
                ->route('home.live-first.status', ['store_slug' => $store_slug])
                ->with('info', 'You already have a pending or approved application.');
        }
        
        $documentTypes = KycDocumentType::allRequired();
        
        return view('home.pages.live_first.kyc', compact('store', 'documentTypes'));
    }

    /**
     * Submit KYC application
     */
    public function submitKyc(Request $request, $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $user = Auth::guard('customer')->user();
        
        Log::info('live_first.kyc.submission_started', [
            'user_id' => $user->id,
            'store_slug' => $store_slug,
            'has_files' => $request->hasFile('document_nin'),
        ]);
        
        // Check for file upload errors first
        $fileErrors = [];
        $maxSizes = [
            'document_video' => 102400, // 100MB in KB
            'default' => 10240, // 10MB in KB
        ];
        
        foreach ($request->allFiles() as $key => $file) {
            if (is_array($file)) continue;
            
            $maxSize = $maxSizes[$key] ?? $maxSizes['default'];
            $fileSizeKB = $file->getSize() / 1024;
            
            if ($fileSizeKB > $maxSize) {
                $maxMB = $maxSize / 1024;
                $actualMB = round($fileSizeKB / 1024, 2);
                $fileErrors[$key] = "File is too large ({$actualMB}MB). Maximum allowed is {$maxMB}MB.";
            }
        }
        
        if (!empty($fileErrors)) {
            Log::warning('live_first.kyc.file_size_exceeded', [
                'user_id' => $user->id,
                'errors' => $fileErrors,
            ]);
            
            return redirect()->back()
                ->withErrors($fileErrors)
                ->withInput()
                ->with('error', 'Some files are too large. Please check the file size limits.');
        }
        
        // Validate the request
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date|before:today',
            'phone_number' => 'required|string|max:20',
            'employer_name' => 'required|string|max:255',
            'years_with_employer' => 'required|numeric|min:0|max:50',
            'state_of_origin' => 'required|string|max:255',
            'lga_of_origin' => 'required|string|max:255',
            'community' => 'nullable|string|max:255',
            'village' => 'nullable|string|max:255',
            'residential_state' => 'required|string|max:255',
            'residential_lga' => 'required|string|max:255',
            'residential_address' => 'required|string',
            
            // Document uploads
            'document_nin' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'document_payslip_old' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'document_payslip_recent' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'document_video' => 'required|file|mimes:mp4,mov,avi|max:102400',
            'document_selfie' => 'required|file|mimes:jpg,jpeg,png|max:10240',
            'document_appointment_letter' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'document_bank_authorization' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        
        if ($validator->fails()) {
            Log::warning('live_first.kyc.validation_failed', [
                'user_id' => $user->id,
                'errors' => $validator->errors()->toArray(),
            ]);
            
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        
        Log::info('live_first.kyc.validation_passed', [
            'user_id' => $user->id,
            'full_name' => $request->full_name,
            'employer' => $request->employer_name,
        ]);
        
        try {
            DB::beginTransaction();
            
            Log::info('live_first.kyc.creating_application', [
                'user_id' => $user->id,
                'store_id' => $store->id,
            ]);
            
            // Create the application
            $application = LiveFirstApplication::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'status' => 'pending',
                'full_name' => $request->full_name,
                'date_of_birth' => $request->date_of_birth,
                'phone_number' => $request->phone_number,
                'employer_name' => $request->employer_name,
                'years_with_employer' => $request->years_with_employer,
                'state_of_origin' => $request->state_of_origin,
                'lga_of_origin' => $request->lga_of_origin,
                'community' => $request->community,
                'village' => $request->village,
                'residential_state' => $request->residential_state,
                'residential_lga' => $request->residential_lga,
                'residential_address' => $request->residential_address,
                'submitted_at' => now(),
            ]);
            
            // Upload and store documents
            $documentMap = [
                'document_nin' => KycDocumentType::NIN,
                'document_payslip_old' => KycDocumentType::PAYSLIP_OLD,
                'document_payslip_recent' => KycDocumentType::PAYSLIP_RECENT,
                'document_video' => KycDocumentType::VIDEO,
                'document_selfie' => KycDocumentType::SELFIE,
                'document_appointment_letter' => KycDocumentType::APPOINTMENT_LETTER,
                'document_bank_authorization' => KycDocumentType::BANK_AUTHORIZATION,
            ];
            
            foreach ($documentMap as $inputName => $docType) {
                if ($request->hasFile($inputName)) {
                    $file = $request->file($inputName);
                    $fileName = time() . '_' . $docType->value . '_' . $file->getClientOriginalName();
                    
                    Log::info('live_first.kyc.uploading_document', [
                        'user_id' => $user->id,
                        'document_type' => $docType->value,
                        'file_size' => $file->getSize(),
                        'file_name' => $fileName,
                    ]);
                    
                    $filePath = $file->storeAs('live_first_kyc/' . $user->id, $fileName, 'public');
                    
                    LiveFirstKycDocument::create([
                        'application_id' => $application->id,
                        'document_type' => $docType->value,
                        'file_path' => $filePath,
                        'file_name' => $fileName,
                        'file_size' => $file->getSize(),
                    ]);
                }
            }
            
            // Update user's Live First status
            $user->update(['live_first_status' => LiveFirstStatus::PENDING_VERIFICATION->value]);
            
            DB::commit();
            
            Log::info('live_first.kyc.submission_completed', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'documents_count' => $application->documents()->count(),
            ]);
            
            // Send email notifications (queued)
            try {
                // Email to customer
                Mail::to($user->email)->queue(new LiveFirstKycReceivedMail($application));
                Log::info('live_first.kyc.customer_email_queued', ['user_id' => $user->id]);
                
                // Email to admins
                $admins = $this->adminRecipients();
                if (!empty($admins)) {
                    Mail::to($admins)->queue(new AdminLiveFirstKycSubmittedMail($application));
                    Log::info('live_first.kyc.admin_email_queued', [
                        'application_id' => $application->id,
                        'admin_count' => count($admins),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('live_first.kyc.email_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
            
            return redirect()
                ->route('home.live-first.status', ['store_slug' => $store_slug])
                ->with('success', 'Your Live First application has been submitted successfully! We will review your documents and notify you soon.');
                
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('live_first.kyc.submission_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->back()
                ->with('error', 'An error occurred while submitting your application. Please try again.')
                ->withInput();
        }
    }

    /**
     * Show application status page
     */
    public function status(Request $request, $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $user = Auth::guard('customer')->user();
        
        $application = $user->liveFirstApplication()->with('documents')->first();
        
        if (!$application) {
            return redirect()
                ->route('home.live-first.index', ['store_slug' => $store_slug])
                ->with('info', 'You have not submitted a Live First application yet.');
        }
        
        return view('home.pages.live_first.status', compact('store', 'application'));
    }

    /**
     * Get admin email recipients for notifications
     */
    private function adminRecipients(): array
    {
        $emails = User::where('role', 'superadmin')->pluck('email')->filter()->all();
        if (empty($emails) && config('mail.from.address')) {
            $emails = [config('mail.from.address')];
        }
        return $emails;
    }
}
