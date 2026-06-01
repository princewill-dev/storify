<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class CustomerSeeder extends Seeder
{
    protected array $firstNames = [
        'Amina', 'Chidi', 'Funke', 'Ibrahim', 'Ngozi', 'Olumide', 'Tolu', 'Yetunde',
        'Emeka', 'Bolanle', 'Ifeanyi', 'Kelechi', 'Adaobi', 'Chinedu', 'Fatima',
        'Olamide', 'Seyi', 'Temitope', 'Zainab', 'Chukwudi', 'Hauwa', 'Musa',
    ];

    protected array $lastNames = [
        'Okafor', 'Adebayo', 'Mohammed', 'Nwachukwu', 'Balogun', 'Eze',
        'Obi', 'Afolabi', 'Okonkwo', 'Yusuf', 'Adesina', 'Ibrahim',
        'Okoro', 'Taiwo', 'Adamu', 'Ogunleye', 'Anenih', 'Bello',
    ];

    protected array $nigerianStates = [
        'Lagos' => ['Lagos Island', 'Ikeja', 'Lekki', 'Surulere', 'Victoria Island', 'Yaba', 'Ajah'],
        'Abuja' => ['Garki', 'Wuse', 'Maitama', 'Gwarinpa', 'Kubwa', 'Asokoro'],
        'Rivers' => ['Port Harcourt', 'Obio-Akpor', 'Eleme'],
        'Kano' => ['Kano Municipal', 'Nasarawa', 'Tarauni'],
        'Enugu' => ['Enugu North', 'Enugu South', 'Nsukka'],
    ];

    public function run(?int $businessId = null): void
    {
        $business = $businessId
            ? Business::find($businessId)
            : Business::first();

        if (!$business) {
            $this->command?->warn('No business found.');
            return;
        }

        $existingCount = Customer::where('business_id', $business->id)->count();
        if ($existingCount > 0) {
            $this->command?->line("  Business [{$business->name}] already has {$existingCount} customers. Skipping.");
            return;
        }

        $totalCreated = 0;

        foreach ($business->stores as $store) {
            $perStore = rand(5, 8);
            for ($i = 0; $i < $perStore; $i++) {
                $firstName = Arr::random($this->firstNames);
                $lastName = Arr::random($this->lastNames);
                $email = strtolower($firstName . '.' . $lastName . rand(10, 99) . '@email.com');
                $phone = '080' . rand(10000000, 99999999);
                $status = rand(1, 10) <= 1 ? 'suspended' : 'active';

                $customer = Customer::create([
                    'business_id' => $business->id,
                    'account_id' => 'CUS_' . strtoupper(\Illuminate\Support\Str::random(8)),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => bcrypt('password'),
                    'status' => $status,
                    'ip_address' => '127.0.0.1',
                ]);

                $this->seedAddresses($customer);

                $totalCreated++;
            }
        }

        $this->command?->info("Done: {$totalCreated} customers seeded across " . $business->stores->count() . " stores.");
    }

    protected function seedAddresses(Customer $customer): void
    {
        $count = rand(1, 2);
        $states = array_keys($this->nigerianStates);

        for ($i = 0; $i < $count; $i++) {
            $state = Arr::random($states);
            $cities = $this->nigerianStates[$state];

            DeliveryAddress::create([
                'customer_id' => $customer->id,
                'label' => $i === 0 ? 'Home' : 'Office',
                'recipient_name' => $customer->first_name . ' ' . $customer->last_name,
                'recipient_phone' => $customer->phone,
                'street_address' => rand(1, 200) . ' ' . Arr::random(['Main Street', 'Broadway', 'Church Road', 'Market Road', 'Airport Road']),
                'city' => Arr::random($cities),
                'state' => $state,
                'country' => 'Nigeria',
                'is_default' => $i === 0,
            ]);
        }
    }
}
