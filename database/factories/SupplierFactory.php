<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prefixes = [
            'Apex', 'Global', 'Prime', 'Nexus', 'TechVision',
            'Vanguard', 'OmniData', 'Pinnacle', 'Horizon', 'Silverline',
            'Crestview', 'Summit', 'ProActive', 'Dynamic', 'Quantum',
            'Starlight', 'Vortex', 'Synergy', 'Precision', 'Frontier',
        ];
        $suffixes = [
            'Electronics Corp', 'Logistics Spares', 'Packaging Solutions',
            'Computing Supplies', 'Hardware Inc', 'Network Solutions',
            'Components Ltd', 'Office Systems', 'Power & Battery',
            'Storage Solutions', 'Audio & Video', 'Server Infrastructure',
            'Security Systems', 'Industrial Supply', 'Distributors LLC',
        ];

        return [
            'name' => fake()->unique()->randomElement($prefixes).' '.fake()->randomElement($suffixes).' #'.fake()->unique()->numberBetween(100, 999),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress().', '.fake()->city().', '.fake()->randomElement(['CA', 'NY', 'TX', 'IL', 'WA', 'GA', 'CO', 'MA', 'FL', 'OH', 'PA', 'NC', 'MI', 'NJ', 'VA']).' '.fake()->postcode(),
            'status' => 'active',
        ];
    }
}
