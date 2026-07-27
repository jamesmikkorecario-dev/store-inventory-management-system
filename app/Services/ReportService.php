<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
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
        'purchase_orders' => 'Purchase Order Summary',
        'outstanding_orders' => 'Outstanding Orders',
        'supplier_purchases' => 'Supplier Purchase History',
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

    // ─── Purchase Orders ──────────────────────────────────────────────────────

    /**
     * Purchase orders with per order unit and value aggregates.
     *
     * @return Builder<PurchaseOrder>
     */
    public function purchaseOrderQuery(ReportFilters $filters): Builder
    {
        return $this->purchaseOrderBaseQuery($filters)
            ->with(['supplier', 'creator', 'approver'])
            ->orderByDesc('purchase_orders.order_date')
            ->orderByDesc('purchase_orders.id');
    }

    /**
     * Headline purchase order figures for the filtered set.
     *
     * @return array{order_count: int, total_value: float, received_value: float, outstanding_value: float, units_ordered: int, units_received: int, open_orders: int, received_orders: int, cancelled_orders: int, overdue_orders: int}
     */
    public function purchaseOrderSummary(ReportFilters $filters): array
    {
        $openList = "'".implode("','", PurchaseOrder::OPEN_STATUSES)."'";
        $today = Carbon::now()->startOfDay()->toDateString();

        /** @var array<string, mixed> $row */
        $row = (array) $this->purchaseOrderBaseQuery($filters)
            ->toBase()
            ->select([])
            ->reorder()
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(purchase_orders.total_amount), 0) as total_value')
            ->selectRaw('COALESCE(SUM(po_items.received_value), 0) as received_value')
            ->selectRaw('COALESCE(SUM(po_items.outstanding_value), 0) as outstanding_value')
            ->selectRaw('COALESCE(SUM(po_items.units_ordered), 0) as units_ordered')
            ->selectRaw('COALESCE(SUM(po_items.units_received), 0) as units_received')
            ->selectRaw("COALESCE(SUM(CASE WHEN purchase_orders.status in ({$openList}) THEN 1 ELSE 0 END), 0) as open_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN purchase_orders.status = '".PurchaseOrder::STATUS_RECEIVED."' THEN 1 ELSE 0 END), 0) as received_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN purchase_orders.status = '".PurchaseOrder::STATUS_CANCELLED."' THEN 1 ELSE 0 END), 0) as cancelled_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN purchase_orders.status in ({$openList}) and purchase_orders.expected_delivery_date is not null and purchase_orders.expected_delivery_date < ? THEN 1 ELSE 0 END), 0) as overdue_orders", [$today])
            ->first();

        return [
            'order_count' => (int) ($row['order_count'] ?? 0),
            'total_value' => round((float) ($row['total_value'] ?? 0), 2),
            'received_value' => round((float) ($row['received_value'] ?? 0), 2),
            'outstanding_value' => round((float) ($row['outstanding_value'] ?? 0), 2),
            'units_ordered' => (int) ($row['units_ordered'] ?? 0),
            'units_received' => (int) ($row['units_received'] ?? 0),
            'open_orders' => (int) ($row['open_orders'] ?? 0),
            'received_orders' => (int) ($row['received_orders'] ?? 0),
            'cancelled_orders' => (int) ($row['cancelled_orders'] ?? 0),
            'overdue_orders' => (int) ($row['overdue_orders'] ?? 0),
        ];
    }

    /**
     * Open purchase orders that still have undelivered units, soonest due first.
     *
     * @return Builder<PurchaseOrder>
     */
    public function outstandingOrderQuery(ReportFilters $filters): Builder
    {
        return $this->purchaseOrderBaseQuery($filters)
            ->with(['supplier'])
            ->whereIn('purchase_orders.status', PurchaseOrder::OPEN_STATUSES)
            ->whereRaw('COALESCE(po_items.units_ordered, 0) > COALESCE(po_items.units_received, 0)')
            ->orderByRaw('case when purchase_orders.expected_delivery_date is null then 1 else 0 end')
            ->orderBy('purchase_orders.expected_delivery_date')
            ->orderBy('purchase_orders.po_number');
    }

    /**
     * Aggregates for the outstanding orders report.
     *
     * @return array{order_count: int, units_outstanding: int, outstanding_value: float, overdue_orders: int, due_within_week: int}
     */
    public function outstandingOrderSummary(ReportFilters $filters): array
    {
        $today = Carbon::now()->startOfDay()->toDateString();
        $weekAhead = Carbon::now()->startOfDay()->addDays(7)->toDateString();

        /** @var array<string, mixed> $row */
        $row = (array) $this->outstandingOrderQuery($filters)
            ->toBase()
            ->select([])
            ->reorder()
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(COALESCE(po_items.units_ordered, 0) - COALESCE(po_items.units_received, 0)), 0) as units_outstanding')
            ->selectRaw('COALESCE(SUM(po_items.outstanding_value), 0) as outstanding_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN purchase_orders.expected_delivery_date is not null and purchase_orders.expected_delivery_date < ? THEN 1 ELSE 0 END), 0) as overdue_orders', [$today])
            ->selectRaw('COALESCE(SUM(CASE WHEN purchase_orders.expected_delivery_date between ? and ? THEN 1 ELSE 0 END), 0) as due_within_week', [$today, $weekAhead])
            ->first();

        return [
            'order_count' => (int) ($row['order_count'] ?? 0),
            'units_outstanding' => (int) ($row['units_outstanding'] ?? 0),
            'outstanding_value' => round((float) ($row['outstanding_value'] ?? 0), 2),
            'overdue_orders' => (int) ($row['overdue_orders'] ?? 0),
            'due_within_week' => (int) ($row['due_within_week'] ?? 0),
        ];
    }

    /**
     * Purchase history rolled up per supplier.
     *
     * @return Builder<Supplier>
     */
    public function supplierPurchaseQuery(ReportFilters $filters): Builder
    {
        $orders = $this->purchaseOrderBaseQuery($filters)->toBase()->reorder();

        $query = Supplier::query()
            ->joinSub($orders, 'po', 'po.supplier_id', '=', 'suppliers.id')
            ->select('suppliers.id', 'suppliers.name', 'suppliers.contact_person', 'suppliers.email', 'suppliers.status')
            ->selectRaw('COUNT(po.id) as order_count')
            ->selectRaw('COALESCE(SUM(po.total_amount), 0) as total_value')
            ->selectRaw('COALESCE(SUM(po.received_value), 0) as received_value')
            ->selectRaw('COALESCE(SUM(po.units_ordered), 0) as units_ordered')
            ->selectRaw('COALESCE(SUM(po.units_received), 0) as units_received')
            ->selectRaw("COALESCE(SUM(CASE WHEN po.status = '".PurchaseOrder::STATUS_RECEIVED."' THEN 1 ELSE 0 END), 0) as received_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN po.status = '".PurchaseOrder::STATUS_CANCELLED."' THEN 1 ELSE 0 END), 0) as cancelled_orders")
            ->selectRaw('MAX(po.order_date) as last_order_date')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.contact_person', 'suppliers.email', 'suppliers.status');

        return $query->orderByDesc('total_value')->orderBy('suppliers.name');
    }

    /**
     * Aggregates for the supplier purchase history report.
     *
     * @return array{supplier_count: int, order_count: int, total_value: float, received_value: float, units_ordered: int, units_received: int, top_supplier: string|null}
     */
    public function supplierPurchaseSummary(ReportFilters $filters): array
    {
        $subQuery = $this->supplierPurchaseQuery($filters)->toBase()->reorder();

        /** @var array<string, mixed> $row */
        $row = (array) DB::query()
            ->fromSub($subQuery, 'supplier_purchases')
            ->selectRaw('COUNT(*) as supplier_count')
            ->selectRaw('COALESCE(SUM(order_count), 0) as order_count')
            ->selectRaw('COALESCE(SUM(total_value), 0) as total_value')
            ->selectRaw('COALESCE(SUM(received_value), 0) as received_value')
            ->selectRaw('COALESCE(SUM(units_ordered), 0) as units_ordered')
            ->selectRaw('COALESCE(SUM(units_received), 0) as units_received')
            ->first();

        /** @var Supplier|null $top */
        $top = $this->supplierPurchaseQuery($filters)->first();

        return [
            'supplier_count' => (int) ($row['supplier_count'] ?? 0),
            'order_count' => (int) ($row['order_count'] ?? 0),
            'total_value' => round((float) ($row['total_value'] ?? 0), 2),
            'received_value' => round((float) ($row['received_value'] ?? 0), 2),
            'units_ordered' => (int) ($row['units_ordered'] ?? 0),
            'units_received' => (int) ($row['units_received'] ?? 0),
            'top_supplier' => $top?->name,
        ];
    }

    // ─── Shared query building ────────────────────────────────────────────────

    /**
     * Base purchase order query joined against per order line item aggregates.
     *
     * @return Builder<PurchaseOrder>
     */
    private function purchaseOrderBaseQuery(ReportFilters $filters): Builder
    {
        $itemTotals = DB::table('purchase_order_items')
            ->select('purchase_order_id')
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COALESCE(SUM(quantity_ordered), 0) as units_ordered')
            ->selectRaw('COALESCE(SUM(quantity_received), 0) as units_received')
            ->selectRaw('COALESCE(SUM(quantity_received * unit_cost), 0) as received_value')
            ->selectRaw('COALESCE(SUM((quantity_ordered - quantity_received) * unit_cost), 0) as outstanding_value')
            ->groupBy('purchase_order_id');

        $query = PurchaseOrder::query()
            ->leftJoinSub($itemTotals, 'po_items', 'po_items.purchase_order_id', '=', 'purchase_orders.id')
            ->select('purchase_orders.*')
            ->selectRaw('COALESCE(po_items.line_count, 0) as line_count')
            ->selectRaw('COALESCE(po_items.units_ordered, 0) as units_ordered')
            ->selectRaw('COALESCE(po_items.units_received, 0) as units_received')
            ->selectRaw('COALESCE(po_items.received_value, 0) as received_value')
            ->selectRaw('COALESCE(po_items.outstanding_value, 0) as outstanding_value');

        if ($filters->supplierId) {
            $query->where('purchase_orders.supplier_id', $filters->supplierId);
        }

        if ($filters->purchaseOrderStatus !== null && array_key_exists($filters->purchaseOrderStatus, PurchaseOrder::STATUSES)) {
            $query->where('purchase_orders.status', $filters->purchaseOrderStatus);
        }

        if ($filters->startDate) {
            $query->where('purchase_orders.order_date', '>=', Carbon::parse($filters->startDate)->toDateString());
        }

        if ($filters->endDate) {
            $query->where('purchase_orders.order_date', '<=', Carbon::parse($filters->endDate)->toDateString());
        }

        if ($filters->productId) {
            $query->whereHas('items', fn (Builder $items) => $items->where('product_id', $filters->productId));
        }

        if ($filters->search) {
            $term = '%'.$filters->search.'%';
            $query->where(function (Builder $searchQuery) use ($term) {
                $searchQuery->where('purchase_orders.po_number', 'like', $term)
                    ->orWhere('purchase_orders.notes', 'like', $term)
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $term));
            });
        }

        return $query;
    }

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
