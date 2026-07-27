<?php

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Database\Seeders\DatabaseSeeder;

test('the database seeder produces a purchase order for every workflow status', function () {
    $this->seed(DatabaseSeeder::class);

    $statuses = PurchaseOrder::query()->pluck('status')->unique()->sort()->values()->all();

    expect($statuses)->toEqual([
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_CANCELLED,
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        PurchaseOrder::STATUS_RECEIVED,
        PurchaseOrder::STATUS_SUBMITTED,
    ]);
});

test('seeded purchase orders have consistent numbering totals and line items', function () {
    $this->seed(DatabaseSeeder::class);

    $orders = PurchaseOrder::with('items')->get();

    expect($orders)->not->toBeEmpty()
        ->and($orders->pluck('po_number')->unique())->toHaveCount($orders->count());

    foreach ($orders as $order) {
        $expectedTotal = $order->items->sum(fn ($item) => round($item->quantity_ordered * (float) $item->unit_cost, 2));

        expect($order->items)->not->toBeEmpty()
            ->and($order->po_number)->toStartWith('PO-')
            ->and(round((float) $order->total_amount, 2))->toBe(round($expectedTotal, 2));

        foreach ($order->items as $item) {
            expect($item->quantity_received)->toBeLessThanOrEqual($item->quantity_ordered);
        }
    }
});

test('seeded receipts leave product stock reconciled against transaction history', function () {
    $this->seed(DatabaseSeeder::class);

    // Every receipt against a purchase order must have produced a stock in transaction.
    $receiptUnits = (int) InventoryTransaction::where('remarks', 'like', 'Received against purchase order%')->sum('quantity');
    $receivedUnits = (int) PurchaseOrder::with('items')->get()->sum(fn ($order) => $order->totalReceived());

    expect($receiptUnits)->toBe($receivedUnits)
        ->and($receiptUnits)->toBeGreaterThan(0);

    // Stock levels must still equal the sum of the signed transaction history.
    foreach (Product::all() as $product) {
        $ledger = (int) InventoryTransaction::where('product_id', $product->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_in' THEN quantity WHEN type = 'stock_out' THEN -quantity ELSE quantity END), 0) as net")
            ->value('net');

        expect($product->current_stock)->toBe($ledger, "stock mismatch for product {$product->id}");
    }
});

test('the seeded pipeline populates the dashboard purchase order metrics', function () {
    $this->seed(DatabaseSeeder::class);

    $metrics = app(PurchaseOrderService::class)->dashboardMetrics();

    expect($metrics['open_orders'])->toBeGreaterThan(0)
        ->and($metrics['awaiting_approval'])->toBeGreaterThan(0)
        ->and($metrics['pending_deliveries'])->toBeGreaterThan(0)
        ->and($metrics['overdue_deliveries'])->toBeGreaterThan(0)
        ->and($metrics['recently_received'])->not->toBeEmpty();
});
