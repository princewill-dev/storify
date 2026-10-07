<?php

namespace App\Http\Resources\Admin;

use App\Models\KycApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-3 (admin console) — the KYC queue row.
 *
 * The legacy columns plus the document indicators a reviewer scans for before
 * opening the application. The queue passes rows in with
 * KycReviewRepository::RELATIONS eager-loaded; the approve/reject responses
 * shape a `fresh()` row with the same relations loaded.
 *
 * @property-read KycApplication $resource
 */
class KycApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KycApplication $application */
        $application = $this->resource;

        return [
            'id' => $application->id,
            'status' => $application->status,
            'status_label' => $application->status_metadata['label'] ?? ucfirst($application->status),
            'legal_name' => $application->legal_name,
            'submitted_at' => $application->submitted_at?->toISOString(),
            'reviewed_at' => ($application->approved_at ?? $application->rejected_at)?->toISOString(),
            'business' => $application->business ? [
                'id' => $application->business->id,
                'name' => $application->business->name,
                'business_code' => $application->business->business_code,
                'status' => $application->business->status,
            ] : null,
            'owner' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'email' => $application->user->email,
                'phone' => $application->user->phone,
                'account_code' => $application->user->account_code,
                'status' => $application->user->status,
            ] : null,
            'reviewer' => $application->reviewer ? [
                'id' => $application->reviewer->id,
                'name' => $application->reviewer->name,
            ] : null,
            'has_identification_document' => (bool) $application->identification_document_path,
            'has_selfie' => (bool) $application->selfie_image_path,
        ];
    }
}
