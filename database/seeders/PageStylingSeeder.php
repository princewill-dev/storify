<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PageStyling;

class PageStylingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pages = [
            [
                'page_name' => 'product_details',
                'page_label' => 'Product Details Page',
                'background_color' => '#ffffff',
                'custom_css' => null,
                'is_active' => true,
            ],
            [
                'page_name' => 'home',
                'page_label' => 'Home Page',
                'background_color' => '#ffffff',
                'custom_css' => null,
                'is_active' => false,
            ],
            [
                'page_name' => 'checkout',
                'page_label' => 'Checkout Page',
                'background_color' => '#f8f9fa',
                'custom_css' => null,
                'is_active' => false,
            ],
        ];

        foreach ($pages as $page) {
            PageStyling::updateOrCreate(
                ['page_name' => $page['page_name']],
                $page
            );
        }

        $this->command->info('Page stylings seeded successfully!');
    }
}
