<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'user_id' => User::factory(),
            'status' => 'active',
        ];
    }

    /**
     * A business that has named the bank account its sales are paid into.
     *
     * The state exists because under a system payment processor that is the
     * difference between a business that can sell and one that cannot, and the
     * two are otherwise indistinguishable on a factory-made row.
     *
     * The bank is attached after creation rather than passed into the definition,
     * so that when none is given the row it makes belongs to *this* business —
     * a factory-made payout bank on someone else's account is a shape the
     * application would never write and no test should be handed.
     */
    public function withPayoutBank(?StoreBank $bank = null): static
    {
        return $this->state(fn (): array => ['payout_entity_type' => 'company'])
            ->afterCreating(function (Business $business) use ($bank): void {
                $business->forceFill([
                    'payout_bank_id' => ($bank ?? StoreBank::factory()->for($business)->create())->id,
                ])->save();
            });
    }
}
