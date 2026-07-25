<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate reporting queries for the advanced reports module.
 *
 * Every method below relies on database side aggregation (SUM/COUNT/CASE) and
 * eager loading, so report generation keeps a constant query count regardless of
 * how many products, suppliers or transactions are involved.
 */
class ReportService
{
    /**
     * @var array<string, string>
     */
    public const REPORT_TYPES = [
        'valuation' => 'Inventory Valuation',
        'low_stock' => 'Low Stock',
        'dead_stock' => 'Dead Stock',
        'transactions' => 'Transaction Summary',
        'supplier_performance' => 'Supplier Performance',
    ];

    /**
     * @var list<int>
     */
    public const INACTIVITY_PERIODS = [30, 60, 90];

    // ─── Inventory Valuation ──────────────────────────────────────────────────

    /**
     * Headline valuation figures for the filtered product set.
     *
     * @return array{product_count: int, total_units: int, total_cost_value: float, total_retail_value: float, potential_profit: float}
     */
    public function valuationSummary(ReportFilters $filters): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $this->productQuery($filters)
            ->toBase()
            ->select([])
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(products.current_stock), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.current_stock * products.cost_price), 0) as total_cost_value')
            ->selectRaw('COALESCE(SUM(products.current_stock * products.selling_price), 0) as total_retail_value')
            ->first();

        $costValue = round((float) ($row['total_cost_value'] ?? 0), 2);
        $retailValue = round((float) ($row['total_retail_value'] ?? 0), 2);

        return [
            'product_count' => (int) ($row['product_count'] ?? 0),
            'total_units' => (int) ($row['total_units'] ?? 0),
            'total_cost_value' => $costValue,
            'total_retail_value' => $retailValue,
            'potential_profit' => round($retailValue - $costValue, 2),
        ];
    }

    /**
     * Inventory value grouped by category.
     *
     * @return Collection<int, array{label: string, product_count: int, total_units: int, total_value: float}>
     */
    public function valuationByCategory(ReportFilters $filters): Collection
    {
        $rows = $this->productQuery($filters)
            ->toBase()
            ->select([])
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as label")
            ->selectRaw('COUNT(products.id) as product_count')
            ->selectRaw('COALESCE(SUM(products.current_stock), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.current_stock * products.cost_price), 0) as total_value')
            ->groupBy('label')
            ->orderByDesc('total_value')
            ->get();

        return $this->mapGroupedValueRows($rows);
    }

    /**
     * Inventory value grouped by supplier.
     *
     * @return Collection<int, array{label: string, product_count: int, total_units: int, total_value: float}>
     */
    public function valuationBySupplier(ReportFilters $filters): Collection
    {
        $rows = $this->productQuery($filters)
            ->toBase()
            ->select([])
            ->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')
            ->selectRaw("COALESCE(suppliers.name, 'Unassigned') as label")
            ->selectRaw('COUNT(products.id) as product_count')
            ->selectRaw('COALESCE(SUM(products.current_stock), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.current_stock * products.cost_price), 0) as total_value')
            ->groupBy('label')
            ->orderByDesc('total_value')
            ->get();

        return $this->mapGroupedValueRows($rows);
    }

    /**
     * Highest value products (current stock × cost price) within the filtered set.
     *
     * @return EloquentCollection<int, Product>
     */
    public function topValueProducts(ReportFilters $filters, int $limit = 10): EloquentCollection
    {
        return $this->valuationQuery($filters)->limit($limit)->get();
    }

    /**
     * Product level valuation rows.
     *
     * @return Builder<Product>
     */
    public function valuationQuery(ReportFilters $filters): Builder
    {
        return $this->productQuery($filters)
            ->with(['category', 'supplier'])
            ->select('products.*')
            ->selectRaw('(products.current_stock * products.cost_price) as inventory_value')
            ->selectRaw('(products.current_stock * products.selling_price) as retail_value')
            ->orderByDesc('inventory_value')
            ->orderBy('products.name');
    }

    // ─── Low Stock ────────────────────────────────────────────────────────────

    /**
     * Products at or below their minimum stock threshold.
     *
     * @return Builder<Product>
     */
    public function lowStockQuery(ReportFilters $filters): Builder
    {
        $query = $this->productQuery($filters)
            ->with(['category', 'supplier'])
            ->select('products.*')
            ->selectRaw('(products.minimum_stock - products.current_stock) as stock_shortfall')
            ->whereColumn('products.current_stock', '<=', 'products.minimum_stock');

        if ($filters->severity === 'critical') {
            $query->whereRaw('(products.current_stock = 0 or products.current_stock * 2 <= products.minimum_stock)');
        } elseif ($filters->severity === 'low') {
            $query->whereRaw('(products.current_stock > 0 and products.current_stock * 2 > products.minimum_stock)');
        }

        return $query->orderBy('products.current_stock')->orderBy('products.name');
    }

    /**
     * Low stock counts by severity bucket.
     *
     * @return array{total: int, critical: int, low: int, out_of_stock: int, units_required: int}
     */
    public function lowStockSummary(ReportFilters $filters): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $this->lowStockQuery($filters)
            ->toBase()
            ->select([])
            ->reorder()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN products.current_stock = 0 or products.current_stock * 2 <= products.minimum_stock THEN 1 ELSE 0 END), 0) as critical')
            ->selectRaw('COALESCE(SUM(CASE WHEN products.current_stock > 0 and products.current_stock * 2 > products.minimum_stock THEN 1 ELSE 0 END), 0) as low')
            ->selectRaw('COALESCE(SUM(CASE WHEN products.current_stock = 0 THEN 1 ELSE 0 END), 0) as out_of_stock')
            ->selectRaw('COALESCE(SUM(products.minimum_stock - products.current_stock), 0) as units_required')
            ->first();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'critical' => (int) ($row['critical'] ?? 0),
            'low' => (int) ($row['low'] ?? 0),
            'out_of_stock' => (int) ($row['out_of_stock'] ?? 0),
            'units_required' => (int) ($row['units_required'] ?? 0),
        ];
    }

    /**
     * Severity bucket for a product inside the low stock report.
     */
    public function severityFor(Product $product): string
    {
        if ($product->current_stock === 0 || $product->current_stock * 2 <= $product->minimum_stock) {
            return 'critical';
        }

        return 'low';
    }

    // ─── Dead Stock ───────────────────────────────────────────────────────────

    /**
     * Products with no inventory movement inside the inactivity window.
     *
     * @return Builder<Product>
     */
    public function deadStockQuery(ReportFilters $filters): Builder
    {
        $cutoff = $this->inactivityCutoff($filters);

        $lastMovement = DB::table('inventory_transactions')
            ->selectRaw('product_id, MAX(transaction_date) as last_transaction_date')
            ->groupBy('product_id');

        return $this->productQuery($filters)
            ->with(['category', 'supplier'])
            ->leftJoinSub($lastMovement, 'movements', 'movements.product_id', '=', 'products.id')
            ->select('products.*')
            ->addSelect('movements.last_transaction_date')
            ->selectRaw('(products.current_stock * products.cost_price) as inventory_value')
            ->where(function (Builder $query) use ($cutoff) {
                $query->whereNull('movements.last_transaction_date')
                    ->orWhere('movements.last_transaction_date', '<', $cutoff);
            })
            ->orderBy('movements.last_transaction_date')
            ->orderBy('products.name');
    }

    /**
     * Dead stock headline figures.
     *
     * @return array{total: int, total_units: int, tied_value: float, never_moved: int}
     */
    public function deadStockSummary(ReportFilters $filters): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $this->deadStockQuery($filters)
            ->toBase()
            ->select([])
            ->reorder()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(products.current_stock), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.current_stock * products.cost_price), 0) as tied_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN movements.last_transaction_date is null THEN 1 ELSE 0 END), 0) as never_moved')
            ->first();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'total_units' => (int) ($row['total_units'] ?? 0),
            'tied_value' => round((float) ($row['tied_value'] ?? 0), 2),
            'never_moved' => (int) ($row['never_moved'] ?? 0),
        ];
    }

    /**
     * Days elapsed since the product last moved, or null when it never moved.
     */
    public function daysSinceLastMovement(Product $product): ?int
    {
        if (! $product->last_transaction_date) {
            return null;
        }

        return (int) Carbon::parse($product->last_transaction_date)->startOfDay()
            ->diffInDays(Carbon::now()->startOfDay());
    }

    // ─── Transaction Summary ──────────────────────────────────────────────────

    /**
     * Filtered inventory transactions with their relations eager loaded.
     *
     * @return Builder<InventoryTransaction>
     */
    public function transactionQuery(ReportFilters $filters): Builder
    {
        $query = InventoryTransaction::query()
            ->with(['product.category', 'product.supplier', 'user']);

        if ($filters->startDate) {
            $query->where('transaction_date', '>=', Carbon::parse($filters->startDate)->startOfDay());
        }

        if ($filters->endDate) {
            $query->where('transaction_date', '<=', Carbon::parse($filters->endDate)->endOfDay());
        }

        if ($filters->transactionType) {
            $query->where('type', $filters->transactionType);
        }

        if ($filters->productId) {
            $query->where('product_id', $filters->productId);
        }

        if ($filters->categoryId !== null || $filters->supplierId !== null || $filters->search !== null) {
            $query->whereHas('product', function (Builder $productQuery) use ($filters) {
                if ($filters->categoryId) {
                    $productQuery->where('category_id', $filters->categoryId);
                }

                if ($filters->supplierId) {
                    $productQuery->where('supplier_id', $filters->supplierId);
                }

                if ($filters->search) {
                    $term = '%'.$filters->search.'%';
                    $productQuery->where(function (Builder $searchQuery) use ($term) {
                        $searchQuery->where('name', 'like', $term)
                            ->orWhere('sku', 'like', $term)
                            ->orWhere('identifier', 'like', $term);
                    });
                }
            });
        }

        return $query->orderByDesc('transaction_date')->orderByDesc('id');
    }

    /**
     * Movement totals for the filtered transaction set.
     *
     * @return array{transaction_count: int, stock_in: int, stock_out: int, adjustments: int, net_movement: int, stock_in_value: float, stock_out_value: float}
     */
    public function transactionSummary(ReportFilters $filters): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $this->transactionQuery($filters)
            ->toBase()
            ->select([])
            ->reorder()
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_in' THEN quantity ELSE 0 END), 0) as stock_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_out' THEN quantity ELSE 0 END), 0) as stock_out")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'adjustment' THEN quantity ELSE 0 END), 0) as adjustments")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_in' THEN quantity * COALESCE(unit_cost, 0) ELSE 0 END), 0) as stock_in_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_out' THEN quantity * COALESCE(unit_price, 0) ELSE 0 END), 0) as stock_out_value")
            ->first();

        $stockIn = (int) ($row['stock_in'] ?? 0);
        $stockOut = (int) ($row['stock_out'] ?? 0);
        $adjustments = (int) ($row['adjustments'] ?? 0);

        return [
            'transaction_count' => (int) ($row['transaction_count'] ?? 0),
            'stock_in' => $stockIn,
            'stock_out' => $stockOut,
            'adjustments' => $adjustments,
            'net_movement' => $stockIn - $stockOut + $adjustments,
            'stock_in_value' => round((float) ($row['stock_in_value'] ?? 0), 2),
            'stock_out_value' => round((float) ($row['stock_out_value'] ?? 0), 2),
        ];
    }

    // ─── Supplier Performance ─────────────────────────────────────────────────

    /**
     * Supplier ranking by inventory contribution.
     *
     * @return Builder<Supplier>
     */
    public function supplierPerformanceQuery(ReportFilters $filters): Builder
    {
        $query = Supplier::query()
            ->leftJoin('products', function ($join) use ($filters) {
                $join->on('products.supplier_id', '=', 'suppliers.id')
                    ->whereNull('products.deleted_at');

                if ($filters->categoryId) {
                    $join->where('products.category_id', '=', $filters->categoryId);
                }
            })
            ->select('suppliers.id', 'suppliers.name', 'suppliers.contact_person', 'suppliers.email', 'suppliers.status')
            ->selectRaw('COUNT(products.id) as products_supplied')
            ->selectRaw('COALESCE(SUM(products.current_stock), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.current_stock * products.cost_price), 0) as total_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN products.id is not null and products.current_stock <= products.minimum_stock THEN 1 ELSE 0 END), 0) as low_stock_products')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.contact_person', 'suppliers.email', 'suppliers.status');

        if ($filters->supplierId) {
            $query->where('suppliers.id', $filters->supplierId);
        }

        if ($filters->search) {
            $term = '%'.$filters->search.'%';
            $query->where(function (Builder $searchQuery) use ($term) {
                $searchQuery->where('suppliers.name', 'like', $term)
                    ->orWhere('suppliers.contact_person', 'like', $term)
                    ->orWhere('suppliers.email', 'like', $term);
            });
        }

        if ($filters->startDate) {
            $query->where('suppliers.created_at', '>=', Carbon::parse($filters->startDate)->startOfDay());
        }

        if ($filters->endDate) {
            $query->where('suppliers.created_at', '<=', Carbon::parse($filters->endDate)->endOfDay());
        }

        return $query->orderByDesc('total_value')->orderBy('suppliers.name');
    }

    /**
     * Aggregate supplier performance figures, computed from the grouped query.
     *
     * @return array{supplier_count: int, product_count: int, total_units: int, total_value: float, low_stock_products: int, top_supplier: string|null}
     */
    public function supplierPerformanceSummary(ReportFilters $filters): array
    {
        $subQuery = $this->supplierPerformanceQuery($filters)->toBase()->reorder();

        /** @var array<string, mixed> $row */
        $row = (array) DB::query()
            ->fromSub($subQuery, 'supplier_stats')
            ->selectRaw('COUNT(*) as supplier_count')
            ->selectRaw('COALESCE(SUM(products_supplied), 0) as product_count')
            ->selectRaw('COALESCE(SUM(total_units), 0) as total_units')
            ->selectRaw('COALESCE(SUM(total_value), 0) as total_value')
            ->selectRaw('COALESCE(SUM(low_stock_products), 0) as low_stock_products')
            ->first();

        /** @var Supplier|null $top */
        $top = $this->supplierPerformanceQuery($filters)->first();

        return [
            'supplier_count' => (int) ($row['supplier_count'] ?? 0),
            'product_count' => (int) ($row['product_count'] ?? 0),
            'total_units' => (int) ($row['total_units'] ?? 0),
            'total_value' => round((float) ($row['total_value'] ?? 0), 2),
            'low_stock_products' => (int) ($row['low_stock_products'] ?? 0),
            'top_supplier' => $top?->name,
        ];
    }

    // ─── Shared query building ────────────────────────────────────────────────

    /**
     * Base product query with the product oriented filters applied.
     *
     * @return Builder<Product>
     */
    private function productQuery(ReportFilters $filters): Builder
    {
        $query = Product::query();

        if ($filters->categoryId) {
            $query->where('products.category_id', $filters->categoryId);
        }

        if ($filters->supplierId) {
            $query->where('products.supplier_id', $filters->supplierId);
        }

        if ($filters->search) {
            $term = '%'.$filters->search.'%';
            $query->where(function (Builder $searchQuery) use ($term) {
                $searchQuery->where('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term)
                    ->orWhere('products.identifier', 'like', $term);
            });
        }

        if ($filters->startDate) {
            $query->where('products.created_at', '>=', Carbon::parse($filters->startDate)->startOfDay());
        }

        if ($filters->endDate) {
            $query->where('products.created_at', '<=', Carbon::parse($filters->endDate)->endOfDay());
        }

        return $query;
    }

    /**
     * Cutoff timestamp for the dead stock inactivity window.
     */
    private function inactivityCutoff(ReportFilters $filters): Carbon
    {
        $days = in_array($filters->inactivityDays, self::INACTIVITY_PERIODS, true)
            ? $filters->inactivityDays
            : 30;

        return Carbon::now()->subDays($days)->startOfDay();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, array{label: string, product_count: int, total_units: int, total_value: float}>
     */
    private function mapGroupedValueRows(Collection $rows): Collection
    {
        return $rows->map(function (mixed $row): array {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            return [
                'label' => (string) ($values['label'] ?? 'Unknown'),
                'product_count' => (int) ($values['product_count'] ?? 0),
                'total_units' => (int) ($values['total_units'] ?? 0),
                'total_value' => round((float) ($values['total_value'] ?? 0), 2),
            ];
        })->values();
    }
}
