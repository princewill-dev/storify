<?php

namespace Database\Seeders;

use App\Models\CompanyService;
use Illuminate\Database\Seeder;

class CompanyServiceSeeder extends Seeder
{
    /**
     * The services this platform actually provides.
     *
     * The previous set was inherited from the `zimozi_store` codebase this one
     * grew out of — grocery sourcing, bulk purchase, salary advances — none of
     * which this platform offers, and each carrying a `page_link` into a site
     * that no longer exists. These are the five capabilities a modern
     * e-commerce platform is bought for, written as the services a prospective
     * customer is choosing between.
     *
     * `page_link` is null throughout on purpose: it is a site-relative path
     * rendered as `/<link>`, and no per-service page exists on the marketing
     * site. Pointing every card at /pricing to make the "Learn more" link
     * appear would be a worse page, not a fuller one.
     *
     * @var array<int, array<string, mixed>>
     */
    private const SERVICES = [
        [
            'order' => 1,
            'title' => 'Online Storefront',
            'description' => 'Your own storefront on your own domain — products, prices, categories and checkout, built from what you already sell. Publish it, share the link, and take orders online.',
            'status' => 'active',
            'page_link' => null,
        ],
        [
            'order' => 2,
            'title' => 'Inventory Management',
            'description' => 'Every product, variant and stock level in one place. Online orders, counter sales and warehouse transfers all move the same count, so what you see is what you have.',
            'status' => 'active',
            'page_link' => null,
        ],
        [
            'order' => 3,
            'title' => 'Warehouse & Multi-location',
            'description' => 'Run stock across several warehouses and shops. Transfer between locations, watch low-stock alerts, and see exactly what is on hand where — without a spreadsheet.',
            'status' => 'active',
            'page_link' => null,
        ],
        [
            'order' => 4,
            'title' => 'Point of Sale',
            'description' => 'Sell in-store from the same inventory as your online shop. Cash, card and transfer payments recorded against the sale, every transaction attributed to the staff member and till.',
            'status' => 'active',
            'page_link' => null,
        ],
        [
            'order' => 5,
            'title' => 'Payments & Checkout',
            'description' => 'Card payments, verified bank transfers and cash at the counter. Refunds and part-payments stay tied to the original order, so the books reconcile themselves.',
            'status' => 'active',
            'page_link' => null,
        ],
    ];

    /**
     * Rows inherited from the `zimozi_store` codebase.
     *
     * Deleted rather than left alone because the upsert below is keyed on
     * `title`: replacing the payload would not remove the old rows, it would
     * seed five new ones alongside all six of these, and the marketing site
     * would show eleven services.
     *
     * @var array<int, string>
     */
    private const LEGACY_TITLES = [
        'SHOP FROM US',
        'SHOP4ME',
        'BULK PURCHASE',
        'FAMILY PACK',
        'INTERNATIONAL SUPPLY',
        'LIVE FIRST',
    ];

    public function run(): void
    {
        CompanyService::whereIn('title', self::LEGACY_TITLES)->delete();

        foreach (self::SERVICES as $data) {
            CompanyService::updateOrCreate(
                ['title' => $data['title']],
                $data
            );
        }
    }
}
