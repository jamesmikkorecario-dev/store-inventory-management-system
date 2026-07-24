<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsService
{
    /**
     * Get total inventory value (current_stock × cost_price).
     */
    public function getTotalInventoryValue(?int $supplierId = null): float
    {
        $query = Product::query();

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $value = $query->selectRaw('SUM(current_stock * cost_price) as total_value')
            ->value('total_value');

        return (float) ($value ?? 0.0);
    }

    /**
     * Get low stock product count (at or below minimum stock).
     */
    public function getLowStockCount(?int $supplierId = null): int
    {
        $query = Product::whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        return $query->count();
    }

    /**
     * Get out of stock product count (stock = 0).
     */
    public function getOutOfStockCount(?int $supplierId = null): int
    {
        $query = Product::where('current_stock', 0);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        return $query->count();
    }

    /**
     * Get active product count.
     */
    public function getActiveProductCount(?int $supplierId = null): int
    {
        $query = Product::where('status', 'active');

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        return $query->count();
    }

    /**
     * Get stock movement trend data (stock_in vs stock_out over time).
     *
     * @return array{labels: list<string>, stock_in: list<int>, stock_out: list<int>}
     */
    public function getStockMovementTrend(int $days = 30, ?int $supplierId = null): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $query = InventoryTransaction::query()
            ->where('transaction_date', '>=', $startDate)
            ->whereIn('type', ['stock_in', 'stock_out']);

        if ($supplierId) {
            $query->whereHas('product', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            });
        }

        $transactions = $query
            ->selectRaw('DATE(transaction_date) as date, type, SUM(ABS(quantity)) as total_qty')
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get();

        $labels = [];
        $stockIn = [];
        $stockOut = [];

        $period = Carbon::now()->subDays($days)->startOfDay()
            ->toPeriod(Carbon::now()->endOfDay(), '1 day');

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $labels[] = $date->format('M d');

            $dayData = $transactions->where('date', $dateStr);
            $stockIn[] = (int) $dayData->where('type', 'stock_in')->sum('total_qty');
            $stockOut[] = (int) $dayData->where('type', 'stock_out')->sum('total_qty');
        }

        return [
            'labels' => $labels,
            'stock_in' => $stockIn,
            'stock_out' => $stockOut,
        ];
    }

    /**
     * Get inventory activity overview (all transaction volume by date).
     *
     * @return array{labels: list<string>, activity: list<int>}
     */
    public function getInventoryActivityOverview(int $days = 30, ?int $supplierId = null): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $query = InventoryTransaction::query()
            ->where('transaction_date', '>=', $startDate);

        if ($supplierId) {
            $query->whereHas('product', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            });
        }

        $transactions = $query
            ->selectRaw('DATE(transaction_date) as date, COUNT(*) as tx_count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $labels = [];
        $activity = [];

        $period = Carbon::now()->subDays($days)->startOfDay()
            ->toPeriod(Carbon::now()->endOfDay(), '1 day');

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $labels[] = $date->format('M d');
            $activity[] = (int) ($transactions[$dateStr]->tx_count ?? 0);
        }

        return [
            'labels' => $labels,
            'activity' => $activity,
        ];
    }

    /**
     * Get top 5 moving products by transaction volume within period.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function getTopMovingProducts(int $days = 30, ?int $supplierId = null): Collection
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $query = Product::query()
            ->join('inventory_transactions', 'products.id', '=', 'inventory_transactions.product_id')
            ->where('inventory_transactions.transaction_date', '>=', $startDate)
            ->select(
                'products.id',
                'products.name',
                'products.current_stock',
                DB::raw('COUNT(inventory_transactions.id) as total_movements')
            )
            ->groupBy('products.id', 'products.name', 'products.current_stock')
            ->orderByDesc('total_movements')
            ->limit(5);

        if ($supplierId) {
            $query->where('products.supplier_id', $supplierId);
        }

        return $query->get();
    }

    /**
     * Get slow moving products (least or no transactions in period).
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function getSlowMovingProducts(int $days = 30, ?int $supplierId = null): Collection
    {
        $query = Product::query()
            ->where('products.status', 'active')
            ->where('products.current_stock', '>', 0)
            ->leftJoin('inventory_transactions', function ($join) {
                $join->on('products.id', '=', 'inventory_transactions.product_id');
            })
            ->select(
                'products.id',
                'products.name',
                'products.current_stock',
                DB::raw('MAX(inventory_transactions.transaction_date) as last_transaction_date')
            )
            ->groupBy('products.id', 'products.name', 'products.current_stock')
            ->orderByRaw('MAX(inventory_transactions.transaction_date) ASC')
            ->limit(5);

        if ($supplierId) {
            $query->where('products.supplier_id', $supplierId);
        }

        return $query->get()->map(function ($product) {
            $product->days_since_last_transaction = $product->last_transaction_date
                ? (int) Carbon::parse($product->last_transaction_date)->diffInDays(Carbon::now())
                : null;

            return $product;
        });
    }

    /**
     * Get inventory health summary.
     *
     * @return array{healthy: int, low_stock: int, out_of_stock: int, healthy_pct: float, low_stock_pct: float, out_of_stock_pct: float, total: int}
     */
    public function getInventoryHealth(?int $supplierId = null): array
    {
        $query = Product::where('status', 'active');

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $total = $query->count();

        $outOfStock = (clone $query)->where('current_stock', 0)->count();
        $lowStock = (clone $query)
            ->whereColumn('current_stock', '<=', 'minimum_stock')
            ->where('current_stock', '>', 0)
            ->count();
        $healthy = $total - $lowStock - $outOfStock;

        return [
            'healthy' => $healthy,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'healthy_pct' => $total > 0 ? round(($healthy / $total) * 100, 1) : 0.0,
            'low_stock_pct' => $total > 0 ? round(($lowStock / $total) * 100, 1) : 0.0,
            'out_of_stock_pct' => $total > 0 ? round(($outOfStock / $total) * 100, 1) : 0.0,
            'total' => $total,
        ];
    }
}
