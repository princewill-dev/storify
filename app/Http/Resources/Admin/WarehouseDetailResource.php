<?php

namespace App\Http\Resources\Admin;

use App\Models\Section;
use App\Models\Warehouse;
use App\Repositories\Admin\WarehouseRepository;
use Illuminate\Http\Request;

/**
 * AD-14 (WS14) — the platform warehouse detail payload.
 *
 * Everything the legacy detail page answered on top of the directory row:
 * the address/contact card, the description, the low-stock band it counted
 * with, and the sections table.
 *
 * The sections table shows the products assigned to a section: the new
 * stack's sections never hold stock-location rows (only stores and
 * warehouses are morph locations), so legacy's "stock items count" has no
 * equivalent to read. The section rows arrive from
 * {@see WarehouseRepository::loadForDetail()}, which
 * eager-loads `products_count` and orders by name.
 *
 * `low_stock_threshold` is the repository's band constant, wired in by the
 * controller: the number the screen reports and the number the counts were
 * built with must stay the same value.
 *
 * @property-read Warehouse $resource
 */
final class WarehouseDetailResource extends WarehouseResource
{
    public function __construct(Warehouse $warehouse, private readonly int $lowStockThreshold)
    {
        parent::__construct($warehouse);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->resource;

        $data = parent::toArray($request);

        $data['country'] = $warehouse->country;
        $data['contact_person'] = $warehouse->contact_person;
        $data['contact_phone'] = $warehouse->contact_phone;
        $data['description'] = $warehouse->description;
        $data['low_stock_threshold'] = $this->lowStockThreshold;
        $data['sections'] = $warehouse->sections->map(fn (Section $section) => [
            'id' => $section->id,
            'section_code' => $section->section_code,
            'name' => $section->name,
            'products_count' => (int) ($section->products_count ?? 0),
            'status' => $section->status->value,
            'status_label' => $section->status->label(),
        ])->all();

        return $data;
    }
}
