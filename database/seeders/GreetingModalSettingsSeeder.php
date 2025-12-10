<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class GreetingModalSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $setting = Setting::first();
        
        if ($setting) {
            $setting->update([
                'company_description' => 'Welcome to our store! We offer a wide range of quality products and exceptional services to meet all your needs.',
                'greeting_modal_enabled' => true,
                'greeting_modal_frequency' => 'once_per_day', // Options: always, once_per_session, once_per_day, once_per_week, once_per_month, never
            ]);
            
            $this->command->info('Greeting modal settings updated successfully!');
        } else {
            $this->command->warn('No settings record found. Please create a settings record first.');
        }
    }
}
