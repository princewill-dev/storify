<?php

namespace App\Http\Resources\Admin;

use App\Models\KycApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-3 (admin console) — the full review payload.
 *
 * Everything legacy stored is surfaced here — including the selfie, the
 * document type/id and the raw payload (a deliberate improvement per the
 * audit: legacy persisted these and never displayed them) — plus the owner's
 * earlier applications, so a reviewer can see a rejection the resubmission was
 * meant to fix. The history rows arrive from KycReviewRepository::historyFor()
 * so response shaping issues no queries of its own.
 */
final class KycApplicationDetailResource extends KycApplicationResource
{
    /**
     * @param  Collection<int, KycApplication>  $history
     */
    public function __construct(KycApplication $application, private readonly Collection $history)
    {
        parent::__construct($application);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KycApplication $application */
        $application = $this->resource;

        return parent::toArray($request) + [
            'phone_number' => $application->phone_number,
            'date_of_birth' => $application->date_of_birth?->toDateString(),
            'address_line' => $application->address_line,
            'city' => $application->city,
            'state' => $application->state,
            'country' => $application->country,
            'device_type' => $application->device_type,
            'browser' => $application->browser,
            'ip_address' => $application->ip_address,
            'kyc_document_type_id' => $application->kyc_document_type_id,
            'document_type' => $application->documentType ? [
                'id' => $application->documentType->id,
                'name' => $application->documentType->name,
                'code' => $application->documentType->code,
            ] : null,
            'kyc_document_id' => $application->kyc_document_id,
            'identification_document_url' => $application->identification_document_path
                ? asset('storage/'.$application->identification_document_path)
                : null,
            'selfie_url' => $application->selfie_image_path
                ? asset('storage/'.$application->selfie_image_path)
                : null,
            // Deliberate: legacy persisted this array and never displayed it.
            'payload' => $application->payload,
            'review_notes' => $application->review_notes,
            'approved_at' => $application->approved_at?->toISOString(),
            'rejected_at' => $application->rejected_at?->toISOString(),
            'can_review' => $application->status === KycApplication::STATUS_SUBMITTED,
            'history' => $this->history(),
        ];
    }

    /**
     * The same owner's earlier applications, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(): array
    {
        return $this->history->map(fn (KycApplication $previous) => [
            'id' => $previous->id,
            'status' => $previous->status,
            'status_label' => $previous->status_metadata['label'] ?? ucfirst($previous->status),
            'submitted_at' => $previous->submitted_at?->toISOString(),
            'reviewed_at' => ($previous->approved_at ?? $previous->rejected_at)?->toISOString(),
            'review_notes' => $previous->review_notes,
        ])->values()->all();
    }
}
