<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\StoreBank;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreBankFactory extends Factory
{
    protected $model = StoreBank::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'bank_name' => 'Zenith Bank',
            'bank_code' => '057',
            // Ten digits, as the registration flow's own validation requires —
            // a factory that produced anything else would let a test pass a rule
            // the application enforces.
            'account_number' => (string) $this->faker->numerify('##########'),
            'account_name' => $this->faker->name(),
            'is_primary' => true,
            // What `PaymentSettingsService::addBankAccount()` writes, and what
            // the column defaults to: the account name is resolved against the
            // bank before the row is saved, so a stored row is a verified one.
            'is_verified' => true,
        ];
    }
}
