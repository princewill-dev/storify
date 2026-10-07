<?php

namespace App\Services\Management;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductFileService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * WS-25 — the three bulk workflows.
 *
 * Each keeps the write order the controller used, inside the same
 * DB::transaction boundary: bulk edit row by row (a model refusal is reported,
 * not fatal), bulk status as the single business-scoped mass update, and bulk
 * delete image files, digital files, then the row. This layer owns the
 * workflows and their transactions; the controller keeps the HTTP shape
 * (status codes, message strings, the envelope) and ProductListRepository the
 * access-scoped reads.
 *
 * Two legacy behaviours are deliberately not carried over (see the controller
 * docblock): every row goes through the same per-product guard as a single
 * edit, and ids the caller cannot reach are reported back as skipped.
 */
final class ProductBulkService
{
    /**
     * Bulk edit. Each row carries its own optional fields; blank fields leave
     * the product as it is, exactly as the legacy modal left untouched inputs
     * alone.
     *
     * @param  EloquentCollection<int, Product>  $products  the caller's access-scoped read, keyed by id
     * @param  array<int, array<string, mixed>>  $rows  validated products[] payload, in submitted order
     * @return array{updated_ids: array<int, int>, rejected: array<int, array{id: int, message: string}>}
     */
    public function applyEdits(EloquentCollection $products, array $rows): array
    {
        $updatedIds = [];
        $rejected = [];

        DB::transaction(function () use ($products, $rows, &$updatedIds, &$rejected) {
            foreach ($rows as $row) {
                $product = $products->get((int) $row['id']);

                if (! $product) {
                    continue;
                }

                $changes = $this->changes($row);

                if ($changes === []) {
                    continue;
                }

                try {
                    $product->update($changes);
                    $updatedIds[] = $product->id;
                } catch (ValidationException $e) {
                    // The Product model refuses some edits a store product may
                    // not hold (e.g. a zero price). Report the row instead of
                    // failing the whole batch — legacy's query-builder write
                    // skipped the guard entirely.
                    $rejected[] = ['id' => $product->id, 'message' => $this->firstMessage($e)];
                }
            }
        });

        return ['updated_ids' => $updatedIds, 'rejected' => $rejected];
    }

    /**
     * Bulk activate/deactivate.
     *
     * The ids were read through the access scope by the caller; the business
     * scope on the write keeps a row that changed hands in between out of
     * reach.
     *
     * @param  Collection<int, int>  $ids
     */
    public function applyStatus(User $user, Collection $ids, string $status): void
    {
        DB::transaction(fn () => Product::where('business_id', $user->business_id)
            ->whereIn('id', $ids)
            ->update(['status' => $status]));
    }

    /**
     * Bulk delete. Stored images and digital files go with the row, and the
     * delete runs in a transaction so a failure part-way cannot leave a
     * half-cleaned catalog.
     *
     * @param  EloquentCollection<int, Product>  $products  the caller's access-scoped read, with images and files
     * @return array<int, int> the deleted ids, in the read's order
     */
    public function deleteProducts(EloquentCollection $products): array
    {
        DB::transaction(function () use ($products) {
            foreach ($products as $product) {
                foreach ($product->images as $image) {
                    $this->deleteImageFile($image);
                    $image->delete();
                }

                app(ProductFileService::class)->deleteAllFiles($product);

                // Stock locations carry a cascading FK, so a deleted product
                // cannot leave stock rows behind (the orphaning class of bug
                // the warehouse delete path fixed).
                $product->delete();
            }
        });

        return $products->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * The fields a bulk row actually submits — a null or empty input means
     * "no change".
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function changes(array $row): array
    {
        $changes = [];

        foreach (['amount', 'quantity', 'stock_quantity'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $changes[$field] = $row[$field];
            }
        }

        if (isset($row['status'])) {
            $changes['status'] = $row['status'];
        }

        return $changes;
    }

    private function firstMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        $messages = reset($errors);

        if (! is_array($messages)) {
            return (string) $e->getMessage();
        }

        return (string) reset($messages);
    }

    private function deleteImageFile(ProductImage $image): void
    {
        try {
            Storage::disk('public')->delete($image->path);
        } catch (\Throwable $e) {
            // Non-fatal: the row goes regardless, matching legacy cleanup.
        }
    }
}
