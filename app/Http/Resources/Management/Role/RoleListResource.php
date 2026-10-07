<?php

namespace App\Http\Resources\Management\Role;

use Illuminate\Http\Request;

/**
 * An index row of the base RoleController's catalogue.
 *
 * A strict superset of RoleResource: it inherits the identity + permissions
 * shape and inserts `users_count` directly after `name`, where the inline map
 * placed it. The row order (id, name, users_count, permissions) is part of the
 * response contract, not an accident of array building.
 *
 * `users_count` keeps the inline map's `?? 0` fallback — the index always
 * eager-counts users, but the fallback is part of the payload's behaviour.
 */
final class RoleListResource extends RoleResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = parent::toArray($request);

        $ordered = [];

        foreach ($payload as $key => $value) {
            $ordered[$key] = $value;

            if ($key === 'name') {
                $ordered['users_count'] = $this->resource->users_count ?? 0;
            }
        }

        return $ordered;
    }
}
