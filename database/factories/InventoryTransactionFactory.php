<?php

namespace Database\Factories;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['stock_in', 'stock_out', 'adjustment']),
            'quantity' => fake()->numberBetween(1, 50),
            'unit_cost' => fake()->randomFloat(2, 5, 100),
            'unit_price' => fake()->randomFloat(2, 10, 200),
            'remarks' => fake()->sentence(),
            'transaction_date' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Set the transaction type to stock_in.
     */
    public function stockIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'stock_in',
        ]);
    }

    /**
     * Set the transaction type to stock_out.
     */
    public function stockOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'stock_out',
        ]);
    }

    /**
     * Set the transaction type to adjustment.
     */
    public function adjustment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'adjustment',
        ]);
    }
}
