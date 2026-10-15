<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\BusinessRegistrationType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\UpdateBusinessRegistrationRequest;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Whether the business is registered, and the certificate that says so.
 *
 * ## Its own endpoint, beside `PATCH business`
 *
 * `BusinessProfileController` owns the two names and the owner's phone. This is
 * a different kind of write: it carries a file, it has a branch that *clears*
 * three fields, and it answers a question the profile form does not ask. Folding
 * it into that endpoint would make one route's contract two, and the review of a
 * name change would have to reason about a certificate upload.
 *
 * ## Scoped to the caller
 *
 * `$user->business`, never an id from the request — the same rule the rest of the
 * management API follows, and the reason this controller cannot be made to write
 * another business's row.
 *
 * ## Why the file is replaced rather than appended
 *
 * One certificate per business, overwritten on upload, because it is evidence
 * for a claim that is either current or replaced. The superseded file is deleted
 * *after* the transaction commits, so a failure part-way leaves the old one on
 * disk and the old path in the row — a business whose certificate vanishes
 * because an unrelated column failed to save is worse than a stale file.
 */
class BusinessRegistrationController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * GET /management/business/registration — the current answer, and the
     * options the form renders.
     */
    public function show(Request $request): JsonResponse
    {
        $business = $this->user($request)->business;

        if (! $business) {
            return $this->error('Set up your business before answering this.', 422);
        }

        return $this->ok([
            'registration' => $this->payload($business),
            'types' => BusinessRegistrationType::options(),
        ]);
    }

    /**
     * PATCH /management/business/registration — record the answer.
     */
    public function update(UpdateBusinessRegistrationRequest $request): JsonResponse
    {
        $business = $this->user($request)->business;

        if (! $business) {
            return $this->error('Set up your business before answering this.', 422);
        }

        $data = $request->validated();
        $registered = (bool) $data['is_registered'];

        $uploaded = $request->hasFile('certificate')
            ? $request->file('certificate')->store('business/certificates', 'public')
            : null;

        // A registered business needs a certificate, and this is the only place
        // that can tell — the rule depends on what is already stored, which a
        // validation rule cannot see. Refused with the file field named so the
        // form can point at it rather than at the form in general.
        if ($registered && $uploaded === null && blank($business->certificate_path)) {
            return $this->error('Upload your registration certificate.', 422, [
                'certificate' => ['Upload your registration certificate.'],
            ]);
        }

        $superseded = $business->certificate_path;

        DB::transaction(function () use ($business, $data, $registered, $uploaded) {
            $business->fill($registered ? [
                'is_registered' => true,
                'registration_type' => $data['registration_type'],
                'registration_number' => $data['registration_number'],
                'certificate_path' => $uploaded ?? $business->certificate_path,
            ] : [
                // Answering "no" clears the rest. Leaving them would mean a
                // business that un-registered still had a certificate on file
                // and a number in the row, and the next reader of either would
                // have to know when they stopped being true.
                'is_registered' => false,
                'registration_type' => null,
                'registration_number' => null,
                'certificate_path' => null,
            ])->save();
        });

        // After the commit, so a rollback never leaves the row pointing at a
        // file that has already been removed.
        if ((! $registered || $uploaded !== null) && filled($superseded)) {
            Storage::disk('public')->delete($superseded);
        }

        return $this->ok(
            ['registration' => $this->payload($business->fresh())],
            $registered ? 'Your registration details are saved.' : 'Saved — your business is not registered.',
        );
    }

    /**
     * The one shape both endpoints answer with, so the form reads the same
     * object whether it has just loaded or just saved.
     *
     * @return array<string, mixed>
     */
    private function payload(Business $business): array
    {
        $type = $business->registration_type !== null
            ? BusinessRegistrationType::tryFrom($business->registration_type)
            : null;

        return [
            'is_registered' => $business->is_registered === null ? null : (bool) $business->is_registered,
            'registration_type' => $type?->value,
            'registration_type_label' => $type?->label(),
            // The label the number field should carry for this type — "BN
            // number" for a Business Name, "RC number" for a company. Sent with
            // the payload rather than derived in the client so the two cannot
            // disagree about what the registry calls it.
            'registration_number_label' => $type?->numberLabel(),
            'registration_number' => $business->registration_number,
            'has_certificate' => filled($business->certificate_path),
            'certificate_url' => filled($business->certificate_path)
                ? asset('storage/'.$business->certificate_path)
                : null,
        ];
    }
}
