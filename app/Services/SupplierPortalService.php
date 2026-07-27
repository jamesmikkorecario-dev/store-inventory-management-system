<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Read-only, supplier-scoped queries and metrics for the supplier portal.
 *
 * Every query built here is constrained to a single supplier at the query level,
 * so a supplier user can never see another supplier's products or purchase orders
 * even if a route guard were somehow bypassed. Nothing in this service mutates
 * data; the portal is deliberately read-only.
 */
class SupplierPortalService
{
    /**
     * Products supplied by the given supplier.
     *
     * @param  array{search?: string|null, status?: string|null, categoryId?: int|string|null}  $filters
     * @return Builder<Product>
     */
    public function productQuery(Supplier $supplier, array $filters = []): Builder
    {
        $query = Product::query()
            ->with(['category'])
            ->where('products.supplier_id', $supplier->id);

        $search = $this->nullableString($filters['search'] ?? null);

        if ($search !== null) {
            $term = '%'.$search.'%';
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term)
                    ->orWhere('products.identifier', 'like', $term);
            });
        }

        if ($this->nullableString($filters['status'] ?? null) !== null) {
            $query->where('products.status', $filters['status']);
        }

        if (! empty($filters['categoryId'])) {
            $query->where('products.category_id', (int) $filters['categoryId']);
        }

        return $query->orderBy('products.name');
    }

    /**
     * Purchase orders raised with the given supplier.
     *
     * @param  array{search?: string|null, status?: string|null, startDate?: string|null, endDate?: string|null}  $filters
     * @return Builder<PurchaseOrder>
     */
    public function purchaseOrderQuery(Supplier $supplier, array $filters = []): Builder
    {
        $query = PurchaseOrder::query()
            ->with(['creator'])
            ->withCount('items')
            ->withSum('items as ordered_units', 'quantity_ordered')
            ->withSum('items as received_units', 'quantity_received')
            ->where('purchase_orders.supplier_id', $supplier->id);

        $search = $this->nullableString($filters['search'] ?? null);

        if ($search !== null) {
            $query->where('purchase_orders.po_number', 'like', '%'.$search.'%');
        }

        $status = $this->nullableString($filters['status'] ?? null);

        if ($status !== null && array_key_exists($status, PurchaseOrder::STATUSES)) {
            $query->where('purchase_orders.status', $status);
        }

        if ($this->nullableString($filters['startDate'] ?? null) !== null) {
            $query->whereDate('purchase_orders.order_date', '>=', $filters['startDate']);
        }

        if ($this->nullableString($filters['endDate'] ?? null) !== null) {
            $query->whereDate('purchase_orders.order_date', '<=', $filters['endDate']);
        }

        return $query->orderByDesc('purchase_orders.order_date')->orderByDesc('purchase_orders.id');
    }

    /**
     * Headline figures for the supplier dashboard.
     *
     * @return array{total_products: int, active_products: int, low_stock_products: int, out_of_stock_products: int, total_units: int, inventory_value: float, open_purchase_orders: int, pending_deliveries: int, awaiting_approval: int}
     */
    public function dashboardMetrics(Supplier $supplier): array
    {
        /** @var array<string, mixed> $products */
        $products = (array) Product::query()
            ->where('supplier_id', $supplier->id)
            ->toBase()
            ->selectRaw('COUNT(*) as total_products')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_products")
            ->selectRaw('COALESCE(SUM(CASE WHEN current_stock <= minimum_stock THEN 1 ELSE 0 END), 0) as low_stock_products')
            ->selectRaw('COALESCE(SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END), 0) as out_of_stock_products')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as total_units')
            ->selectRaw('COALESCE(SUM(current_stock * cost_price), 0) as inventory_value')
            ->first();

        return [
            'total_products' => (int) ($products['total_products'] ?? 0),
            'active_products' => (int) ($products['active_products'] ?? 0),
            'low_stock_products' => (int) ($products['low_stock_products'] ?? 0),
            'out_of_stock_products' => (int) ($products['out_of_stock_products'] ?? 0),
            'total_units' => (int) ($products['total_units'] ?? 0),
            'inventory_value' => round((float) ($products['inventory_value'] ?? 0), 2),
            'open_purchase_orders' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->whereIn('status', PurchaseOrder::OPEN_STATUSES)->count(),
            'pending_deliveries' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES)->count(),
            'awaiting_approval' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->where('status', PurchaseOrder::STATUS_SUBMITTED)->count(),
        ];
    }

    /**
     * Aggregate performance figures across the supplier's whole order history.
     *
     * @return array{order_count: int, received_orders: int, cancelled_orders: int, open_orders: int, units_ordered: int, units_received: int, purchase_value: float, received_value: float, outstanding_units: int, inventory_value: float, first_order_at: Carbon|null, last_order_at: Carbon|null, fulfilment_rate: float}
     */
    public function performanceSummary(Supplier $supplier): array
    {
        /** @var array<string, mixed> $orders */
        $orders = (array) PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->toBase()
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = '".PurchaseOrder::STATUS_RECEIVED."' THEN 1 ELSE 0 END), 0) as received_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = '".PurchaseOrder::STATUS_CANCELLED."' THEN 1 ELSE 0 END), 0) as cancelled_orders")
            ->selectRaw('COALESCE(SUM(total_amount), 0) as purchase_value')
            ->selectRaw('MIN(order_date) as first_order_at')
            ->selectRaw('MAX(order_date) as last_order_at')
            ->first();

        /** @var array<string, mixed> $items */
        $items = (array) PurchaseOrder::query()
            ->where('purchase_orders.supplier_id', $supplier->id)
            ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->toBase()
            ->selectRaw('COALESCE(SUM(purchase_order_items.quantity_ordered), 0) as units_ordered')
            ->selectRaw('COALESCE(SUM(purchase_order_items.quantity_received), 0) as units_received')
            ->selectRaw('COALESCE(SUM(purchase_order_items.quantity_received * purchase_order_items.unit_cost), 0) as received_value')
            ->first();

        $unitsOrdered = (int) ($items['units_ordered'] ?? 0);
        $unitsReceived = (int) ($items['units_received'] ?? 0);

        $inventoryValue = (float) Product::where('supplier_id', $supplier->id)
            ->toBase()
            ->selectRaw('COALESCE(SUM(current_stock * cost_price), 0) as value')
            ->value('value');

        return [
            'order_count' => (int) ($orders['order_count'] ?? 0),
            'received_orders' => (int) ($orders['received_orders'] ?? 0),
            'cancelled_orders' => (int) ($orders['cancelled_orders'] ?? 0),
            'open_orders' => PurchaseOrder::where('supplier_id', $supplier->id)
                ->whereIn('status', PurchaseOrder::OPEN_STATUSES)->count(),
            'units_ordered' => $unitsOrdered,
            'units_received' => $unitsReceived,
            'purchase_value' => round((float) ($orders['purchase_value'] ?? 0), 2),
            'received_value' => round((float) ($items['received_value'] ?? 0), 2),
            'outstanding_units' => max($unitsOrdered - $unitsReceived, 0),
            'inventory_value' => round($inventoryValue, 2),
            'first_order_at' => $this->nullableDate($orders['first_order_at'] ?? null),
            'last_order_at' => $this->nullableDate($orders['last_order_at'] ?? null),
            'fulfilment_rate' => $unitsOrdered > 0 ? round($unitsReceived / $unitsOrdered * 100, 1) : 0.0,
        ];
    }

    /**
     * Products this supplier has delivered the most units of.
     *
     * @return EloquentCollection<int, Product>
     */
    public function topSuppliedProducts(Supplier $supplier, int $limit = 5): EloquentCollection
    {
        return Product::query()
            ->where('products.supplier_id', $supplier->id)
            ->leftJoin('purchase_order_items', 'purchase_order_items.product_id', '=', 'products.id')
            ->leftJoin('purchase_orders', function ($join) use ($supplier) {
                $join->on('purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                    ->where('purchase_orders.supplier_id', '=', $supplier->id)
                    ->whereNull('purchase_orders.deleted_at');
            })
            ->groupBy('products.id')
            ->select('products.*')
            ->selectRaw('COALESCE(SUM(purchase_order_items.quantity_ordered), 0) as units_ordered')
            ->selectRaw('COALESCE(SUM(purchase_order_items.quantity_received), 0) as units_received')
            ->selectRaw('COALESCE(SUM(purchase_order_items.quantity_received * purchase_order_items.unit_cost), 0) as received_value')
            ->orderByDesc('units_received')
            ->orderByDesc('units_ordered')
            ->orderBy('products.name')
            ->limit($limit)
            ->get();
    }

    /**
     * The most recently raised purchase orders for the supplier.
     *
     * @return EloquentCollection<int, PurchaseOrder>
     */
    public function recentPurchaseOrders(Supplier $supplier, int $limit = 5): EloquentCollection
    {
        return $this->purchaseOrderQuery($supplier)->limit($limit)->get();
    }

    /**
     * Resolve a purchase order that provably belongs to the supplier, or null.
     */
    public function findPurchaseOrder(Supplier $supplier, int $purchaseOrderId): ?PurchaseOrder
    {
        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->whereKey($purchaseOrderId)
            ->with(['items.product', 'supplier', 'creator', 'approver'])
            ->first();

        return $order;
    }

    private function nullableString(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }

    private function nullableDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }
}
