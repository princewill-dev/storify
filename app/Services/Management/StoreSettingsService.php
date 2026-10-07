<?php

namespace App\Services\Management;

use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WS-04 — the store details/branding write workflow that spans more than one
 * statement: the logo file on disk and the row in the database, kept in the
 * safe order StoreSettingsController::update() established.
 *
 * The transaction boundary, the orphan cleanup and the post-save delete of
 * the previous logo all survive here at the same points in the sequence:
 *  1. a new logo is stored before the row is touched;
 *  2. the row update runs inside DB::transaction;
 *  3. if the save throws, the just-written file is deleted and the exception
 *     rethrown, so a rejected update never leaves an orphan on disk;
 *  4. the old logo is deleted only after the row carrying the new path is
 *     safely persisted.
 *
 * HTTP stays out: the live-storefront slug refusal and the reserved-slug
 * check happen in the controller before this runs, and the slug arrives
 * already de-duplicated (App\Repositories\Management\StoreSettingsRepository
 * ::uniqueSlug()); null means "do not touch the slug".
 *
 * No money conversion happens here — the update payload has no amount fields.
 */
final class StoreSettingsService
{
    /**
     * @param  array<string, mixed>  $data  validated by UpdateStoreSettingsRequest
     * @param  string|null  $slug  the unique slug to persist, or null to leave the current one
     * @param  UploadedFile|null  $logo  the request file, stored before the row is updated as before
     * @param  bool  $removeLogo  the `remove_logo` flag; ignored when a new file was uploaded
     */
    public function update(Store $store, array $data, ?string $slug, ?UploadedFile $logo, bool $removeLogo): void
    {
        // Partial-update semantics: only the fields present in the request are
        // touched, so saving the Socials card cannot blank the address the way
        // a full-attribute overwrite would. Sending an empty string clears a
        // nullable field (ConvertEmptyStringsToNull turns it into null).
        $attributes = [];

        foreach ([
            'name',
            'description',
            'support_email',
            'support_phone',
            'address',
            'instagram_url',
            'facebook_url',
            'twitter_url',
            'tiktok_url',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($slug !== null) {
            $attributes['slug'] = $slug;
        }

        $oldLogo = $store->logo_path;
        $newLogo = $logo?->store('stores/logos', 'public');

        if ($newLogo) {
            $attributes['logo_path'] = $newLogo;
        } elseif ($removeLogo) {
            $attributes['logo_path'] = null;
        }

        try {
            DB::transaction(fn () => $store->update($attributes));
        } catch (\Throwable $e) {
            // The file exists before the row does; clean it up when the save
            // fails so a rejected update never leaves an orphan on disk.
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }

            throw $e;
        }

        // Only delete the previous logo after the row carrying the new path is
        // safely persisted — legacy did the same, and it is the safe order.
        if (($newLogo || $removeLogo) && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
        }
    }
}
