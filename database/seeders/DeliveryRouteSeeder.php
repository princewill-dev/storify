<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DeliveryRoute;

class DeliveryRouteSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['country' => 'Nigeria','state' => 'Lagos','area' => 'Lekki','fee' => 5000,'delivery_days' => 3,'active' => true],
            ['country' => 'Nigeria','state' => 'Lagos','area' => 'Ikeja','fee' => 4000,'delivery_days' => 3,'active' => true],
            ['country' => 'Nigeria','state' => 'Abuja','area' => 'Wuse','fee' => 6000,'delivery_days' => 4,'active' => true],
            ['country' => 'Nigeria','state' => 'Rivers','area' => 'Port Harcourt','fee' => 5500,'delivery_days' => 4,'active' => true],
            ['country' => 'Nigeria','state' => 'Oyo','area' => 'Ibadan','fee' => 4500,'delivery_days' => 4,'active' => true],
        ];
        foreach ($data as $r) {
            DeliveryRoute::firstOrCreate(
                ['country' => $r['country'], 'state' => $r['state'], 'area' => $r['area']],
                ['fee' => $r['fee'] * 100, 'delivery_days' => $r['delivery_days'], 'active' => $r['active']]
            );
        }
    }
}
