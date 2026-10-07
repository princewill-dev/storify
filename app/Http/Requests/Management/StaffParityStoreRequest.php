<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * WS-20 — the staff invite payload.
 *
 * The controller's inline rules moved verbatim, including the legacy repairs
 * it carried:
 *
 * - A role may only be assigned if it belongs to the acting business. The
 *   legacy API validated `role` as a bare string with a global
 *   `exists:roles,name` check, so an unknown role — or one from another
 *   tenant — 500'd from Spatie's team resolution instead of returning a
 *   validation error. `teamRoleRule()` scopes the exists check per business.
 * - The "Select at least one role" refusal used to be a ValidationException
 *   thrown by the controller's `resolveRoleNames()` before its transaction.
 *   It now runs in the `after()` hook so it stays a 422 on `roles` and still
 *   fires before any work; `roleNames()` exposes the resolved names to the
 *   invite workflow, so the resolution exists once.
 * - The optional pre-set password: the owner may set credentials and the
 *   staff member is then forced to change them on first login (legacy
 *   semantics, enforced by StaffParityService).
 */
final class StaffParityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:staff create` middleware, not a
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', $this->teamRoleRule()],
            'roles' => ['nullable', 'array', 'min:1'],
            'roles.*' => ['string', $this->teamRoleRule()],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['nullable', 'integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['nullable', 'integer'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:5120'],
            'document_tags' => ['nullable', 'array', 'max:10'],
            'document_tags.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Checked only when every field rule passed — the pre-refactor controller
     * ran validate() first and resolved the roles second, so an invalid field
     * never also produced this error.
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

                if ($this->roleNames() === []) {
                    $validator->errors()->add('roles', 'Select at least one role for this staff member.');
                }
            },
        ];
    }

    /**
     * The role names the invite will assign: the multi-role `roles` array
     * when sent, the legacy single `role` otherwise.
     *
     * @return array<int, string>
     */
    public function roleNames(): array
    {
        $names = collect($this->input('roles', []));

        if ($names->isEmpty() && ! empty($this->input('role'))) {
            $names = collect([$this->input('role')]);
        }

        return $names->filter()->unique()->values()->all();
    }

    /**
     * A role may only be assigned if it belongs to the acting business.
     */
    private function teamRoleRule(): Exists
    {
        return Rule::exists('roles', 'name')->where('business_id', (int) $this->user()?->business_id);
    }
}
