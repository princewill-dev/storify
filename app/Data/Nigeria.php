<?php

namespace App\Data;

class Nigeria
{
    public static function states(): array
    {
        return [
            'AB' => 'Abia',
            'AD' => 'Adamawa',
            'AK' => 'Akwa Ibom',
            'AN' => 'Anambra',
            'BA' => 'Bauchi',
            'BY' => 'Bayelsa',
            'BE' => 'Benue',
            'BO' => 'Borno',
            'CR' => 'Cross River',
            'DE' => 'Delta',
            'EB' => 'Ebonyi',
            'ED' => 'Edo',
            'EK' => 'Ekiti',
            'EN' => 'Enugu',
            'FC' => 'FCT — Abuja',
            'GO' => 'Gombe',
            'IM' => 'Imo',
            'JI' => 'Jigawa',
            'KD' => 'Kaduna',
            'KN' => 'Kano',
            'KT' => 'Katsina',
            'KE' => 'Kebbi',
            'KO' => 'Kogi',
            'KW' => 'Kwara',
            'LA' => 'Lagos',
            'NA' => 'Nasarawa',
            'NI' => 'Niger',
            'OG' => 'Ogun',
            'ON' => 'Ondo',
            'OS' => 'Osun',
            'OY' => 'Oyo',
            'PL' => 'Plateau',
            'RI' => 'Rivers',
            'SO' => 'Sokoto',
            'TA' => 'Taraba',
            'YO' => 'Yobe',
            'ZA' => 'Zamfara',
        ];
    }

    public static function topCities(): array
    {
        return [
            'Lagos',
            'Abuja',
            'Kano',
            'Ibadan',
            'Port Harcourt',
            'Benin City',
            'Enugu',
            'Kaduna',
            'Aba',
            'Onitsha',
            'Warri',
            'Jos',
            'Maiduguri',
            'Owerri',
            'Uyo',
            'Calabar',
            'Abeokuta',
            'Akure',
            'Ilorin',
            'Sokoto',
        ];
    }

    public static function citiesByState(string $state): array
    {
        $cities = [
            'Lagos'       => ['Ikeja', 'Lekki', 'Victoria Island', 'Surulere', 'Yaba', 'Apapa', 'Ikorodu', 'Badagry', 'Epe', 'Agege'],
            'FCT – Abuja' => ['Garki', 'Wuse', 'Maitama', 'Asokoro', 'Gwarinpa', 'Kubwa', 'Jabi', 'Lugbe', 'Nyanya', 'Karu'],
            'Kano'        => ['Kano Municipal', 'Nassarawa', 'Tarauni', 'Fagge', 'Gwale', 'Dala', 'Kumbotso', 'Ungogo', 'Bichi', 'Rano'],
            'Oyo'         => ['Ibadan North', 'Ibadan South', 'Egbeda', 'Oluyole', 'Ona Ara', 'Lagelu', 'Akinyele', 'Ido', 'Oyo Town', 'Ogbomoso'],
            'Rivers'      => ['Port Harcourt City', 'Obio-Akpor', 'Eleme', 'Okrika', 'Oyigbo', 'Bonny', 'Emohua', 'Ikwerre', 'Etche', 'Omoku'],
            'Edo'         => ['Oredo (Benin City)', 'Ikpoba-Okha', 'Egor', 'Ovia North-East', 'Uhunmwonde', 'Ovia South-West', 'Orhionmwon', 'Esan West', 'Auchi', 'Ekpoma'],
            'Enugu'       => ['Enugu North', 'Enugu South', 'Nsukka', 'Udi', 'Awgu', 'Oji River', 'Ezeagu', 'Igbo-Etiti', 'Nkanu West', '9th Mile'],
            'Kaduna'      => ['Kaduna North', 'Kaduna South', 'Zaria', 'Kafanchan', 'Saminaka', 'Birnin Gwari', 'Kagoro', 'Kachia', 'Zonkwa', 'Kwoi'],
            'Abia'        => ['Aba North', 'Aba South', 'Osisioma', 'Umuahia North', 'Umuahia South', 'Obingwa', 'Ukwa West', 'Ugwuano', 'Isiala Ngwa', 'Ohafia'],
            'Anambra'    => ['Onitsha North', 'Onitsha South', 'Nnewi North', 'Nnewi South', 'Awka North', 'Awka South', 'Ekwusigo', 'Idemili North', 'Ogbaru', 'Ihiala'],
        ];

        return $cities[$state] ?? [];
    }
}
