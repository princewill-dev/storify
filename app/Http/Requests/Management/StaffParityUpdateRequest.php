<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * WS-20 — the staff edit payload.
 *
 * The controller's inline rules moved verbatim. Two details are deliberate:
 *
 * - `status` accepts only the active/suspended flip: invited staff become
 *   active by accepting the invitation, and deleted staff stay deleted.
 * - A role may only be assigned if it belongs to the acting business (the
 *   legacy global `exists:roles,name` check could reach another tenant's
 *   role, which then threw a 500 from Spatie's team resolution).
 *
 * The "Select at least one role" refusal used to be a ValidationException
 * thrown inside the update transaction by the controller's
 * `resolveRoleNames()`. It now runs in the `after()` hook — but only when the
 * payload actually carries `roles`, exactly as the controller only resolved
 * them behind `$request->has('roles')`. There is no single-`role` fallback
 * here: the edit form never sent one, and honouring it would turn a payload
 * the old code rejected into a successful reassignment.
 */
final class StaffParityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:staff edit` middleware, not a
        // validation gate; the role rules scope themselves by the caller's
        // business below.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'remove_photo' => ['nullable', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', $this->teamRoleRule()],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['nullable', 'integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['nullable', 'integer'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:5120'],
            'document_tags' => ['nullable', 'array', 'max:10'],
            'document_tags.*' => ['nullable', 'string', 'max:100'],
            'delete_document_ids' => ['nullable', 'array'],
            'delete_document_ids.*' => ['integer'],
        ];
    }

    /**
     * Checked only when every field rule passed — the pre-refactor controller
     * ran validate() first and resolved the roles second.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->has('roles') && $this->roleNames() === []) {
                    $validator->errors()->add('roles', 'Select at least one role for this staff member.');
                }
            },
        ];
    }

    /**
     * The role names the reassignment will sync. Read only when the payload
     * carried `roles`; the minimum is enforced by the field rule above.
     *
     * @return array<int, string>
     */
    public function roleNames(): array
    {
        return collect($this->input('roles', []))->filter()->unique()->values()->all();
    }

    /**
     * A role may only be assigned if it belongs to the acting business.
     */
    private function teamRoleRule(): Exists
    {
        return Rule::exists('roles', 'name')->where('business_id', (int) $this->user()?->business_id);
    }
}
