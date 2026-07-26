<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Applies bulk changes to a selection of products.
 *
 * Every action runs inside a transaction and updates models one by one so the
 * activity log and low stock notification listeners still fire, keeping bulk
 * edits auditable and consistent with single record edits.
 */
class ProductBulkActionService
{
    /**
     * Upper bound on the number of products a single bulk action may touch.
     */
    public const MAX_SELECTION = 500;

    /**
     * Reassign the category of every selected product.
     *
     * @param  list<int>  $productIds
     */
    public function updateCategory(array $productIds, int $categoryId): int
    {
        return $this->applyAttributes($productIds, ['category_id' => $categoryId]);
    }

    /**
     * Reassign the supplier of every selected product.
     *
     * @param  list<int>  $productIds
     */
    public function updateSupplier(array $productIds, int $supplierId): int
    {
        return $this->applyAttributes($productIds, ['supplier_id' => $supplierId]);
    }

    /**
     * Set the low stock threshold of every selected product.
     *
     * @param  list<int>  $productIds
     */
    public function updateMinimumStock(array $productIds, int $minimumStock): int
    {
        return $this->applyAttributes($productIds, ['minimum_stock' => $minimumStock]);
    }

    /**
     * Set the catalog status of every selected product.
     *
     * @param  list<int>  $productIds
     */
    public function updateStatus(array $productIds, string $status): int
    {
        return $this->applyAttributes($productIds, ['status' => $status]);
    }

    /**
     * Archive (soft delete) the selected products.
     *
     * Products with recorded inventory transactions are preserved for audit
     * integrity and reported back as blocked, mirroring the single delete rule.
     * Image files are kept so an archived product can be restored intact.
     *
     * @param  list<int>  $productIds
     * @return array{archived: int, blocked: list<string>}
     */
    public function archive(array $productIds): array
    {
        $productIds = $this->sanitizeIds($productIds);

        if ($productIds === []) {
            return ['archived' => 0, 'blocked' => []];
        }

        $archived = 0;
        $blocked = [];

        DB::transaction(function () use ($productIds, &$archived, &$blocked): void {
            $products = Product::query()
                ->withCount('inventoryTransactions')
                ->whereIn('id', $productIds)
                ->get();

            foreach ($products as $product) {
                if ((int) $product->inventory_transactions_count > 0) {
                    $blocked[] = $product->sku;

                    continue;
                }

                $product->delete();
                $archived++;
            }
        });

        return ['archived' => $archived, 'blocked' => $blocked];
    }

    /**
     * Apply the given attributes to every selected product.
     *
     * @param  list<int>  $productIds
     * @param  array<string, int|string>  $attributes
     */
    private function applyAttributes(array $productIds, array $attributes): int
    {
        $productIds = $this->sanitizeIds($productIds);

        if ($productIds === []) {
            return 0;
        }

        $updated = 0;

        DB::transaction(function () use ($productIds, $attributes, &$updated): void {
            foreach (Product::query()->whereIn('id', $productIds)->get() as $product) {
                $product->update($attributes);
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * Normalize the incoming selection to a unique, capped list of positive integers.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function sanitizeIds(array $productIds): array
    {
        $ids = array_filter(array_map('intval', $productIds), fn (int $id): bool => $id > 0);

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_SELECTION);
    }
}
