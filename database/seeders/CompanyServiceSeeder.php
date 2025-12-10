<?php

namespace Database\Seeders;

use App\Models\CompanyService;
use Illuminate\Database\Seeder;

class CompanyServiceSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'order' => 1,
                'title' => 'SHOP FROM US',
                'description' => 'Buy directly from our curated catalog of quality products at great prices.',
                'status' => 'active',
                'page_link' => 'zimozi_store',
            ],
            [
                'order' => 2,
                'title' => 'SHOP4ME',
                'description' => 'Time is precious. SHOP4ME is your premier grocery sourcing and delivery service, tailored specifically for busy corporate workers and high-profile individuals who don\'t have the time or desire to shop for daily essentials.',
                'status' => 'active',
                'page_link' => 'zimozi_store/shop4me',
            ],
            [
                'order' => 3,
                'title' => 'BULK PURCHASE',
                'description' => 'Order in large quantities with negotiated rates tailored to your needs.',
                'status' => 'active',
                'page_link' => 'zimozi_store/bulk-purchase',
            ],
            [
                'order' => 4,
                'title' => 'FAMILY PACK',
                'description' => 'Bundle essential items for families and save more with value packs.',
                'status' => 'active',
                'page_link' => 'zimozi_store/family-pack',
            ],
            [
                'order' => 5,
                'title' => 'INTERNATIONAL SUPPLY',
                'description' => 'Source products across borders with reliable global procurement support.',
                'status' => 'active',
                'page_link' => 'zimozi_store/international-supply',
            ],
            [
                'order' => 6,
                'title' => 'LIVE FIRST',
                'description' => 'Our LIVE FIRST program is designed to help regular salary earners manage their expenses by allowing them to borrow essential food items now and pay for them later when their next salary arrives.',
                'status' => 'active',
                'page_link' => 'zimozi_store/live-first',
            ],
        ];

        foreach ($items as $data) {
            CompanyService::updateOrCreate(
                ['title' => $data['title']], 
                $data
            );
        }
    }
}
