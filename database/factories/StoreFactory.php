<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'store_id' => 'st_' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT),
            'name' => $this->faker->company(),
            'user_id' => \App\Models\User::factory(),
            'business_id' => \App\Models\Business::factory(),
            'status' => 'active',
            'slug' => $this->faker->unique()->slug(),
        ];
    }
}
