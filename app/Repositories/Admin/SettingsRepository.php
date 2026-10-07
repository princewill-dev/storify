<?php

namespace App\Repositories\Admin;

use App\Models\Currency;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * WS-02 (admin) — platform settings & branding reads.
 *
 * Reads only: the save workflow, its transaction boundary, the cache busting,
 * the audit row and the change mail live in SettingsService, and the HTTP
 * shape in SettingsController. The `settings` table is a platform-wide
 * singleton row, so nothing here is tenant-scoped; the only status filter is
 * the picker's deleted-store rule below.
 */
final class SettingsRepository
{
    /**
     * The singleton settings row, or null before the first save.
     */
    public function current(): ?Setting
    {
        return Setting::query()->first();
    }

    /**
     * The single currency flagged `is_default`, as an int for the JSON payload.
     */
    public function defaultCurrencyId(): ?int
    {
        $id = Currency::query()->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The store saved as the homepage, whatever its status: it may have been
     * deleted after it was selected, and the payload still shows what is
     * stored instead of blanking. Deliberately no deleted-store filter.
     */
    public function findStore(int $id): ?Store
    {
        return Store::query()->find($id);
    }

    /**
     * Non-deleted stores for the homepage picker. If the saved homepage store
     * was deleted after selection it is kept in the list (flagged by its
     * status) so the form shows what is actually stored instead of blanking.
     *
     * @return Collection<int, Store>
     */
    public function storeOptions(?int $includeStoreId = null): Collection
    {
        return Store::query()
            ->where(function ($query) use ($includeStoreId) {
                $query->where('status', '!=', Store::STATUS_DELETED)
                    ->when($includeStoreId, fn ($inner) => $inner->orWhere('id', $includeStoreId));
            })
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'status']);
    }

    /**
     * Every currency for the default-currency picker, name-ordered.
     *
     * @return Collection<int, Currency>
     */
    public function currencyOptions(): Collection
    {
        return Currency::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'symbol', 'is_default']);
    }
}
