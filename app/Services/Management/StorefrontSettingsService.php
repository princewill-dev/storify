<?php

namespace App\Services\Management;

use App\Models\Store;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Support\Storefront\StorefrontHtmlSanitizer;
use App\Support\Storefront\StorefrontPages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The storefront settings write workflows.
 *
 * Two things happen here that must not happen in a controller:
 *
 *  - **Sanitization.** Every page body passes through HTMLPurifier on write.
 *    The public endpoint can then serve stored HTML without re-checking it on
 *    every request (DOMPurify checks again on render, deliberately).
 *  - **The file/row ordering.** The OG image follows the same safe sequence as
 *    the store logo: write the file, save the row in a transaction, delete the
 *    new file if the save throws, and delete the old file only after commit.
 */
final class StorefrontSettingsService
{
    public function __construct(
        private readonly StorefrontHtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by UpdateStorefrontSettingsRequest
     */
    public function updateSettings(
        Store $store,
        array $data,
        ?UploadedFile $image,
    ): StorefrontSetting {
        $settings = $store->storefrontSettings()->first()
            ?? new StorefrontSetting([
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'template_key' => 'default',
            ]);

        $attributes = [];

        foreach (['site_title', 'meta_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        $oldPath = $settings->og_image_path;
        $newPath = $image?->store('storefront/og', 'public');
        $remove = (bool) ($data['remove_og_image'] ?? false);

        if ($newPath) {
            $attributes['og_image_path'] = $newPath;
        } elseif ($remove) {
            $attributes['og_image_path'] = null;
        }

        try {
            DB::transaction(fn () => $settings->fill($attributes)->save());
        } catch (Throwable $e) {
            // The file exists before the row does; a rejected save must not
            // leave it behind.
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $e;
        }

        if (($newPath || $remove) && $oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $settings->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  validated by StoreStorefrontPageRequest
     */
    public function createPage(Store $store, array $data): StorefrontPage
    {
        if ($store->storefrontPages()->count() >= StorefrontPages::MAX_PER_STORE) {
            throw ValidationException::withMessages([
                'slug' => ['This storefront has reached the maximum of '.StorefrontPages::MAX_PER_STORE.' pages.'],
            ]);
        }

        return $store->storefrontPages()->create([
            'business_id' => $store->business_id,
            'title' => $data['title'],
            'slug' => $data['slug'],
            'content' => $this->sanitizer->clean($data['content'] ?? null),
            'is_published' => (bool) ($data['is_published'] ?? false),
            'show_in_footer' => (bool) ($data['show_in_footer'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  validated by UpdateStorefrontPageRequest
     */
    public function updatePage(StorefrontPage $page, array $data): StorefrontPage
    {
        $attributes = [];

        foreach (['title', 'slug'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('content', $data)) {
            $attributes['content'] = $this->sanitizer->clean($data['content']);
        }

        foreach (['is_published', 'show_in_footer'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = (bool) $data[$field];
            }
        }

        $page->update($attributes);

        return $page->refresh();
    }

    public function deletePage(StorefrontPage $page): void
    {
        $page->delete();
    }
}
