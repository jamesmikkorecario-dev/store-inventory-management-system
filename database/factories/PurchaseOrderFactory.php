<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orderDate = Carbon::instance(fake()->dateTimeBetween('-60 days', 'now'));

        return [
            'po_number' => 'PO-'.$orderDate->format('Ym').'-'.strtoupper(fake()->unique()->bothify('####')),
            'supplier_id' => Supplier::factory(),
            'created_by' => User::factory(),
            'approved_by' => null,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => $orderDate->toDateString(),
            'expected_delivery_date' => $orderDate->copy()->addDays(fake()->numberBetween(3, 21))->toDateString(),
            'notes' => fake()->optional()->sentence(),
            'total_amount' => 0,
        ];
    }

    /**
     * Order awaiting approval.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PurchaseOrder::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);
    }

    /**
     * Approved order that is ready to receive.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PurchaseOrder::STATUS_APPROVED,
            'submitted_at' => Carbon::now(),
            'approved_at' => Carbon::now(),
            'approved_by' => User::factory(),
        ]);
    }

    /**
     * Order with a partial delivery booked in.
     */
    public function partiallyReceived(): static
    {
        return $this->approved()->state(fn (array $attributes): array => [
            'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ]);
    }

    /**
     * Fully delivered order.
     */
    public function received(): static
    {
        return $this->approved()->state(fn (array $attributes): array => [
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'received_at' => Carbon::now(),
        ]);
    }

    /**
     * Cancelled order.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
        ]);
    }

    /**
     * Order whose expected delivery date has already passed.
     */
    public function overdue(): static
    {
        return $this->approved()->state(fn (array $attributes): array => [
            'expected_delivery_date' => Carbon::now()->subDays(5)->toDateString(),
        ]);
    }
}
