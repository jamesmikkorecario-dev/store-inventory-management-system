<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Product;
use Exception;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Log an inventory transaction and automatically update product stock level.
     * Uses pessimistic locking to prevent concurrency issues and negative stock.
     *
     * @param  string  $type  (stock_in, stock_out, adjustment)
     * @param  int  $quantity  (positive for stock_in/out, signed for adjustment)
     * @param  float|null  $unitCost  overrides the product cost snapshot (e.g. the agreed purchase order cost)
     *
     * @throws Exception
     */
    public function logTransaction(int $productId, int $userId, string $type, int $quantity, ?string $remarks = null, ?float $unitCost = null): InventoryTransaction
    {
        return DB::transaction(function () use ($productId, $userId, $type, $quantity, $remarks, $unitCost) {
            // Lock the product row for update to prevent concurrent updates
            $product = Product::lockForUpdate()->findOrFail($productId);

            // Determine stock change direction
            $stockChange = 0;
            if ($type === 'stock_in') {
                if ($quantity <= 0) {
                    throw new Exception('Stock in quantity must be greater than zero.');
                }
                $stockChange = $quantity;
            } elseif ($type === 'stock_out') {
                if ($quantity <= 0) {
                    throw new Exception('Stock out quantity must be greater than zero.');
                }
                $stockChange = -$quantity;
            } elseif ($type === 'adjustment') {
                $stockChange = $quantity; // Signed quantity (can be positive or negative)
            } else {
                throw new Exception("Invalid transaction type: {$type}");
            }

            $newStock = $product->current_stock + $stockChange;

            // Prevent negative stock
            if ($newStock < 0) {
                throw new Exception("Cannot process transaction. This would result in negative stock (current: {$product->current_stock}, requested change: {$stockChange}).");
            }

            // Update product stock level
            $product->current_stock = $newStock;
            $product->save();

            // Create transaction record capturing snapshots of prices
            return InventoryTransaction::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? $product->cost_price,
                'unit_price' => $product->selling_price,
                'remarks' => $remarks,
                'transaction_date' => now(),
            ]);
        });
    }
}
