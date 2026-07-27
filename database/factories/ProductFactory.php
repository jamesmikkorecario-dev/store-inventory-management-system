<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brands = ['ProBook', 'ThinkPad', 'Latitude', 'UltraSharp', 'ErgoPro', 'Catalyst', 'Vengeance', 'LaserJet', 'PowerBack', 'Evolve', 'OptiPlex', 'DiskStation'];
        $types = ['Laptop', 'Monitor', 'Wireless Mouse', 'Mechanical Keyboard', 'PoE Switch', 'NVMe SSD', 'Docking Station', 'Laser Printer', 'UPS Backup', 'Headset', 'Desktop PC', 'NAS Enclosure'];
        $cost = fake()->randomFloat(2, 20, 500);
        $selling = round($cost * fake()->randomFloat(2, 1.25, 1.8), 2);

        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-???-####')),
            'name' => fake()->randomElement($brands).' '.fake()->randomElement($types).' Gen '.fake()->numberBetween(1, 10),
            'description' => fake()->randomElement([
                'Professional commercial-grade technology hardware designed for high reliability and 24/7 enterprise operation.',
                'Ergonomic and high-performance computing component optimized for modern corporate workflows.',
                'Premium business equipment featuring advanced security controls, energy efficiency, and extended warranty support.',
                'Compact office peripheral engineered for seamless connectivity, fast data transfer, and robust durability.',
                'Standard infrastructure device suitable for office workstations, server racks, and network distribution.',
            ]),
            'category_id' => Category::factory(),
            'supplier_id' => Supplier::factory(),
            'cost_price' => $cost,
            'selling_price' => $selling,
            'current_stock' => fake()->numberBetween(0, 100),
            'minimum_stock' => fake()->numberBetween(5, 20),
            'status' => 'active',
        ];
    }
}
