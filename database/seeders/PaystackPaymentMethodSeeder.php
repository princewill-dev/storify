<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaystackPaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PaymentMethod::updateOrCreate(
            ['code' => 'paystack'],
            [
                'name' => 'Paystack',
                'description' => 'Pay securely with your card, bank account, USSD, or mobile money via Paystack',
                'is_active' => true,
                'config' => [
                    'public_key' => config('services.paystack.public_key'),
                    'supports_cards' => true,
                    'supports_bank_transfer' => true,
                    'supports_ussd' => true,
                    'supports_qr' => true,
                    'supports_mobile_money' => true,
                    'currencies' => ['NGN', 'USD', 'GHS', 'ZAR'],
                    'countries' => ['Nigeria', 'Ghana', 'South Africa'],
                ],
            ]
        );
    }
}
