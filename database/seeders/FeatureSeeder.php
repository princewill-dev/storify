<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            [
                'title' => 'FREE SHIPPING',
                'description' => 'Enjoy free shipping on all orders with no minimum spend.',
                'icon_path' => 'home/images/shipping-icon.png',
                'order' => 1,
            ],
            [
                'title' => '24/7 SUPPORT',
                'description' => 'Our team is available around the clock to assist you.',
                'icon_path' => 'home/images/support-icon.png',
                'order' => 2,
            ],
            [
                'title' => '100% MONEY BACK',
                'description' => 'Satisfaction guaranteed or your money back, no questions asked.',
                'icon_path' => 'home/images/refund-icon.png',
                'order' => 3,
            ],
        ];

        foreach ($features as $feature) {
            Feature::create($feature);
        }
    }
}
