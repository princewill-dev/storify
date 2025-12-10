<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TestimonialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Small placeholder base64 image (1x1 pixel gray image)
        $placeholderImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $testimonials = [
            [
                'name' => 'Sarah Johnson',
                'occupation' => 'Small Business Owner',
                'message' => 'Shopping here has been an incredible experience! The quality of products is outstanding, and the customer service is top-notch. I have been able to grow my business with their support and will continue to recommend them to everyone.',
                'photo' => $placeholderImage,
                'status' => 'active',
                'position' => 1,
            ],
            [
                'name' => 'Michael Chen',
                'occupation' => 'Software Developer',
                'message' => 'The online shopping experience is seamless and user-friendly. I love how easy it is to find exactly what I need. The delivery is always prompt, and the products arrive in perfect condition. Highly recommended!',
                'photo' => $placeholderImage,
                'status' => 'active',
                'position' => 2,
            ],
            [
                'name' => 'Amina Mohammed',
                'occupation' => 'Fashion Designer',
                'message' => 'I have been a loyal customer for over two years now. The variety and quality of products available are unmatched. Every purchase I make exceeds my expectations. Thank you for making shopping so enjoyable!',
                'photo' => $placeholderImage,
                'status' => 'active',
                'position' => 3,
            ],
            [
                'name' => 'David Williams',
                'occupation' => 'Marketing Manager',
                'message' => 'Outstanding service and exceptional product quality! I particularly appreciate the attention to detail and the care taken in packaging. This is my go-to store for all my shopping needs.',
                'photo' => $placeholderImage,
                'status' => 'active',
                'position' => 4,
            ],
            [
                'name' => 'Fatima Ibrahim',
                'occupation' => 'Teacher',
                'message' => 'As a busy professional, I appreciate the convenience and reliability of this store. The products are always of great quality, and the prices are very competitive. I recommend this to all my colleagues!',
                'photo' => $placeholderImage,
                'status' => 'active',
                'position' => 5,
            ],
        ];

        foreach ($testimonials as $testimonial) {
            Testimonial::create($testimonial);
        }
    }
}
