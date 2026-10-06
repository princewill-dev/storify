<?php

namespace App\Http\Requests\Management\PaymentSettings\Concerns;

use App\Models\Store;

/**
 * A store id on a form must be one the user can reach; a plain
 * `exists:stores,id` rule would let a business attach a method to a
 * competitor's store — the check legacy omitted. Shared by the bank-account
 * form and the method-assignment form.
 */
trait ScopesStoreSelection
{
    protected function accessibleStoreRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value && ! $this->user()?->accessibleStores()->where('status', '!=', Store::STATUS_DELETED)->whereKey($value)->exists()) {
                $fail('You do not have access to that store.');
            }
        };
    }
}
