<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'Electronics', 'Fashion', 'Home & Kitchen', 'Beauty & Health', 'Sports & Outdoors'
        ];

        $stores = Store::all();
        if ($stores->isEmpty()) {
            return; // no stores yet
        }

        foreach ($stores as $store) {
            foreach ($defaults as $name) {
                $slugBase = Str::slug($name);
                $slug = $slugBase;
                $i = 1;
                while (Category::where('store_id', $store->id)->where('slug', $slug)->exists()) {
                    $slug = $slugBase.'-'.$i++;
                }
                Category::firstOrCreate(
                    ['store_id' => $store->id, 'slug' => $slug],
                    ['name' => $name, 'status' => 'active']
                );
            }
        }
    }
}
