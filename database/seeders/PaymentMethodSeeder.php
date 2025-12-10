<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = [
            [
                'name' => 'Bank Transfer',
                'code' => 'bank_transfer',
                'description' => 'Make your payment directly into our bank account. Please use your Order ID as the payment reference.',
                'is_active' => true,
                'config' => null,
            ],
            [
                'name' => 'Paystack',
                'code' => 'paystack',
                'description' => 'Pay securely online using your debit/credit card via Paystack.',
                'is_active' => false, // Will be enabled when integrated
                'config' => [
                    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
                    'secret_key' => env('PAYSTACK_SECRET_KEY'),
                ],
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                $method
            );
        }
    }
}
