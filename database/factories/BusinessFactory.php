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
     * The state exists because under collect-and-pay-out that is the difference
     * between a business Storify can hand money over to and one whose money
     * accumulates in Storify's balance until somebody names an account, and the
     * two are otherwise indistinguishable on a factory-made row.
     *
     * It is deliberately *not* what makes a business able to sell. It used to be:
     * a destination had to be registered before a customer could pay, and a
     * business that had not filled in the form could not ring up a sale. That
     * gate is gone, and the fact this state exists for is the payout run's.
     *
     * The bank is attached after creation rather than passed into the definition,
     * so that when none is given the row it makes belongs to *this* business —
     * a factory-made payout bank on someone else's account is a shape the
     * application would never write and no test should be handed.
     */
    public function withPayoutBank(?StoreBank $bank = null): static
    {
        return $this->afterCreating(function (Business $business) use ($bank): void {
            $business->forceFill([
                'payout_bank_id' => ($bank ?? StoreBank::factory()->for($business)->create())->id,
            ])->save();
        });
    }
}
