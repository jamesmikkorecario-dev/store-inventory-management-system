<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            'Laptops & Notebooks',
            'Desktop Workstations',
            'Monitors & Displays',
            'Computer Accessories',
            'Networking Equipment',
            'Storage & Hard Drives',
            'Printers & Scanners',
            'Cables & Adapters',
            'Power & Battery Backup',
            'Audio & Video Conferencing',
            'Server Infrastructure',
            'Office Stationery',
            'Graphic Cards & GPUs',
            'Motherboards & CPUs',
            'Memory & RAM Modules',
            'Cooling & Fans',
            'Security & Surveillance',
            'Point of Sale Systems',
            'Barcode & RFID Readers',
            'Enterprise Software',
        ];

        return [
            'name' => fake()->unique()->randomElement($categories).' '.fake()->unique()->numerify('##'),
            'description' => fake()->randomElement([
                'Essential professional equipment and hardware for corporate environments.',
                'High-performance technology components designed for business productivity.',
                'Reliable office supplies and computing peripherals with extended durability.',
                'Enterprise-grade network and infrastructure solutions for seamless operations.',
                'Standard hardware accessories and tools required for daily workflows.',
            ]),
        ];
    }
}
