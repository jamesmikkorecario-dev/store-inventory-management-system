<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportExporter;
use App\Services\ReportFilters;
use App\Services\ReportService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PurchaseOrderPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the roles and permissions the purchase order module relies on.
 */
function seedPurchaseOrderRoles(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['Admin', 'Staff', 'Supplier'] as $role) {
        Role::findOrCreate($role);
    }

    foreach (['manage inventory', 'view reports', 'export reports'] as $permission) {
        Permission::findOrCreate($permission);
    }

    Role::findByName('Admin')->givePermissionTo(['manage inventory', 'view reports', 'export reports']);
    Role::findByName('Staff')->givePermissionTo(['manage inventory', 'view reports', 'export reports']);

    (new PurchaseOrderPermissionsSeeder)->run();
}

function makeAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

function makeStaff(): User
{
    $user = User::factory()->create();
    $user->assignRole('Staff');

    return $user;
}

function makeSupplierUser(Supplier $supplier): User
{
    $user = User::factory()->create(['supplier_id' => $supplier->id]);
    $user->assignRole('Supplier');

    return $user;
}

beforeEach(function () {
    seedPurchaseOrderRoles();

    $this->supplier = Supplier::factory()->create(['status' => 'active']);
    $this->category = Category::factory()->create();

    $this->productA = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'cost_price' => 10.00,
        'current_stock' => 0,
    ]);

    $this->productB = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'cost_price' => 25.00,
        'current_stock' => 5,
    ]);

    $this->admin = makeAdmin();
    $this->service = app(PurchaseOrderService::class);
});

/**
 * Helper: create a purchase order through the service.
 *
 * @param  list<array{product_id: int, quantity_ordered: int, unit_cost: float}>|null  $items
 */
function createOrder(mixed $test, ?array $items = null, ?User $creator = null): PurchaseOrder
{
    return app(PurchaseOrderService::class)->create(
        [
            'supplier_id' => $test->supplier->id,
            'order_date' => Carbon::now()->toDateString(),
            'expected_delivery_date' => Carbon::now()->addDays(7)->toDateString(),
            'notes' => 'Restock order',
        ],
        $items ?? [
            ['product_id' => $test->productA->id, 'quantity_ordered' => 10, 'unit_cost' => 10.00],
            ['product_id' => $test->productB->id, 'quantity_ordered' => 4, 'unit_cost' => 25.00],
        ],
        $creator ?? $test->admin,
    );
}

// ─── Creation ─────────────────────────────────────────────────────────────────

test('it creates a purchase order with line items and calculated totals', function () {
    $order = createOrder($this);

    expect($order->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and($order->items)->toHaveCount(2)
        ->and((float) $order->total_amount)->toBe(200.00)
        ->and($order->created_by)->toBe($this->admin->id)
        ->and($order->po_number)->toStartWith('PO-'.Carbon::now()->format('Ym').'-');

    $this->assertDatabaseHas('purchase_order_items', [
        'purchase_order_id' => $order->id,
        'product_id' => $this->productA->id,
        'quantity_ordered' => 10,
        'line_total' => 100.00,
    ]);
});

test('it generates sequential and unique purchase order numbers', function () {
    $first = createOrder($this);
    $second = createOrder($this);

    expect($first->po_number)->not->toBe($second->po_number);

    $prefix = 'PO-'.Carbon::now()->format('Ym').'-';

    expect($first->po_number)->toBe($prefix.'0001')
        ->and($second->po_number)->toBe($prefix.'0002');
});

test('it rejects a purchase order without line items', function () {
    expect(fn () => createOrder($this, []))->toThrow(RuntimeException::class);
});

test('it rejects duplicate products on a single purchase order', function () {
    expect(fn () => createOrder($this, [
        ['product_id' => $this->productA->id, 'quantity_ordered' => 2, 'unit_cost' => 5.00],
        ['product_id' => $this->productA->id, 'quantity_ordered' => 3, 'unit_cost' => 5.00],
    ]))->toThrow(RuntimeException::class);
});

test('it rejects line items with a quantity below one', function () {
    expect(fn () => createOrder($this, [
        ['product_id' => $this->productA->id, 'quantity_ordered' => 0, 'unit_cost' => 5.00],
    ]))->toThrow(RuntimeException::class);
});

test('it recalculates the grand total when a draft order is updated', function () {
    $order = createOrder($this);

    $updated = $this->service->update($order, [], [
        ['product_id' => $this->productA->id, 'quantity_ordered' => 3, 'unit_cost' => 10.00],
    ]);

    expect($updated->items)->toHaveCount(1)
        ->and((float) $updated->total_amount)->toBe(30.00);

    $this->assertDatabaseMissing('purchase_order_items', [
        'purchase_order_id' => $order->id,
        'product_id' => $this->productB->id,
    ]);
});

test('it refuses to update a non draft order', function () {
    $order = createOrder($this);
    $this->service->submit($order);

    expect(fn () => $this->service->update($order->refresh(), [], [
        ['product_id' => $this->productA->id, 'quantity_ordered' => 1, 'unit_cost' => 1.00],
    ]))->toThrow(RuntimeException::class);
});

// ─── Status workflow ──────────────────────────────────────────────────────────

test('it walks the full status workflow from draft to received', function () {
    $order = createOrder($this);
    expect($order->status)->toBe(PurchaseOrder::STATUS_DRAFT);

    $order = $this->service->submit($order);
    expect($order->status)->toBe(PurchaseOrder::STATUS_SUBMITTED)
        ->and($order->submitted_at)->not->toBeNull();

    $order = $this->service->approve($order, $this->admin);
    expect($order->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and($order->approved_by)->toBe($this->admin->id)
        ->and($order->approved_at)->not->toBeNull();

    $order->load('items');
    $quantities = $order->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all();

    $order = $this->service->receive($order, $quantities, $this->admin);
    expect($order->status)->toBe(PurchaseOrder::STATUS_RECEIVED)
        ->and($order->received_at)->not->toBeNull();
});

test('it can return a submitted order to draft', function () {
    $order = $this->service->submit(createOrder($this));

    $order = $this->service->revertToDraft($order);

    expect($order->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and($order->submitted_at)->toBeNull();
});

test('it cannot approve an order that was not submitted', function () {
    $order = createOrder($this);

    expect(fn () => $this->service->approve($order, $this->admin))->toThrow(RuntimeException::class);
});

test('it cannot submit an order that has no line items', function () {
    $order = createOrder($this);
    $order->items()->delete();

    expect(fn () => $this->service->submit($order->refresh()))->toThrow(RuntimeException::class);
});

test('it cancels an open order and blocks further transitions', function () {
    $order = $this->service->cancel(createOrder($this), 'Supplier out of stock');

    expect($order->status)->toBe(PurchaseOrder::STATUS_CANCELLED)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($order->notes)->toContain('Supplier out of stock');

    expect(fn () => $this->service->submit($order))->toThrow(RuntimeException::class);
});

test('it cannot cancel a fully received order', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $order = $this->service->receive(
        $order,
        $order->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all(),
        $this->admin,
    );

    expect(fn () => $this->service->cancel($order))->toThrow(RuntimeException::class);
});

// ─── Receiving ────────────────────────────────────────────────────────────────

test('it books partial deliveries and marks the order partially received', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $itemA = $order->items->firstWhere('product_id', $this->productA->id);

    $order = $this->service->receive($order, [$itemA->id => 4], $this->admin);

    expect($order->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

    $itemA->refresh();
    expect($itemA->quantity_received)->toBe(4)
        ->and($itemA->outstandingQuantity())->toBe(6)
        ->and($itemA->isFullyReceived())->toBeFalse();

    expect($this->productA->refresh()->current_stock)->toBe(4);
});

test('receiving updates product stock levels and creates stock in transactions', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $itemA = $order->items->firstWhere('product_id', $this->productA->id);
    $itemB = $order->items->firstWhere('product_id', $this->productB->id);

    $this->service->receive($order, [$itemA->id => 10, $itemB->id => 4], $this->admin);

    expect($this->productA->refresh()->current_stock)->toBe(10)
        ->and($this->productB->refresh()->current_stock)->toBe(9);

    expect(InventoryTransaction::where('type', 'stock_in')->count())->toBe(2);

    $transaction = InventoryTransaction::where('product_id', $this->productA->id)->firstOrFail();

    expect($transaction->quantity)->toBe(10)
        ->and($transaction->user_id)->toBe($this->admin->id)
        ->and((float) $transaction->unit_cost)->toBe(10.00)
        ->and($transaction->remarks)->toContain($order->po_number);
});

test('it uses the agreed purchase order cost for the transaction snapshot', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this, [
        ['product_id' => $this->productA->id, 'quantity_ordered' => 2, 'unit_cost' => 99.50],
    ])), $this->admin);
    $order->load('items');

    $this->service->receive($order, [$order->items->first()->id => 2], $this->admin);

    $transaction = InventoryTransaction::where('product_id', $this->productA->id)->firstOrFail();

    expect((float) $transaction->unit_cost)->toBe(99.50)
        ->and((float) $this->productA->refresh()->cost_price)->toBe(10.00);
});

test('it prevents over receiving a line item', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $itemA = $order->items->firstWhere('product_id', $this->productA->id);

    expect(fn () => $this->service->receive($order, [$itemA->id => 11], $this->admin))
        ->toThrow(RuntimeException::class);

    expect($this->productA->refresh()->current_stock)->toBe(0)
        ->and(InventoryTransaction::count())->toBe(0)
        ->and($order->refresh()->status)->toBe(PurchaseOrder::STATUS_APPROVED);
});

test('it prevents over receiving across multiple partial deliveries', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $itemA = $order->items->firstWhere('product_id', $this->productA->id);

    $this->service->receive($order, [$itemA->id => 6], $this->admin);

    expect(fn () => $this->service->receive($order->refresh(), [$itemA->id => 5], $this->admin))
        ->toThrow(RuntimeException::class);

    expect($itemA->refresh()->quantity_received)->toBe(6)
        ->and($this->productA->refresh()->current_stock)->toBe(6);
});

test('it rejects receiving with no quantities entered', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    expect(fn () => $this->service->receive($order, [$order->items->first()->id => 0], $this->admin))
        ->toThrow(RuntimeException::class);
});

test('it cannot receive an order that has not been approved', function () {
    $order = $this->service->submit(createOrder($this));
    $order->load('items');

    expect(fn () => $this->service->receive($order, [$order->items->first()->id => 1], $this->admin))
        ->toThrow(RuntimeException::class);
});

// ─── Permissions ──────────────────────────────────────────────────────────────

test('admin and staff can reach the purchase orders index', function () {
    $this->actingAs(makeAdmin())->get(route('purchase-orders.index'))->assertOk();
    $this->actingAs(makeStaff())->get(route('purchase-orders.index'))->assertOk();
});

test('suppliers have no access to purchase orders', function () {
    $supplierUser = makeSupplierUser($this->supplier);

    $this->actingAs($supplierUser)->get(route('purchase-orders.index'))->assertForbidden();

    $order = createOrder($this);
    $this->actingAs($supplierUser)->get(route('purchase-orders.show', $order))->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get(route('purchase-orders.index'))->assertRedirect(route('login'));
});

test('staff cannot approve purchase orders but admins can', function () {
    $staff = makeStaff();

    expect($staff->can('approve purchase orders'))->toBeFalse()
        ->and($staff->can('manage purchase orders'))->toBeTrue()
        ->and($staff->can('receive purchase orders'))->toBeTrue()
        ->and($this->admin->can('approve purchase orders'))->toBeTrue();
});

test('the approve action is blocked for users without the approval permission', function () {
    $order = $this->service->submit(createOrder($this));

    Livewire::actingAs(makeStaff())
        ->test('pages::purchase-orders')
        ->assertSet('canApprove', false)
        ->call('approveOrder', $order->id)
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(PurchaseOrder::STATUS_SUBMITTED);
});

test('the receive action is blocked for users without the receive permission', function () {
    $role = Role::findByName('Staff');
    $role->revokePermissionTo('receive purchase orders');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);

    Livewire::actingAs(makeStaff())
        ->test('pages::purchase-order', ['purchaseOrder' => $order])
        ->assertSet('canReceive', false)
        ->call('receive')
        ->assertForbidden();

    expect(InventoryTransaction::count())->toBe(0);
});

// ─── Livewire pages ───────────────────────────────────────────────────────────

test('the index page creates a purchase order through the form', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('openCreateModal')
        ->assertSet('showFormModal', true)
        ->set('supplierId', (string) $this->supplier->id)
        ->set('orderDate', Carbon::now()->toDateString())
        ->set('expectedDeliveryDate', Carbon::now()->addDays(5)->toDateString())
        ->set('lineItems', [
            ['product_id' => (string) $this->productA->id, 'quantity_ordered' => '6', 'unit_cost' => '12.50'],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showFormModal', false);

    $order = PurchaseOrder::with('items')->firstOrFail();

    expect((float) $order->total_amount)->toBe(75.00)
        ->and($order->items)->toHaveCount(1);
});

test('the index page validates the purchase order form', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('openCreateModal')
        ->set('supplierId', '')
        ->set('lineItems', [['product_id' => '', 'quantity_ordered' => '0', 'unit_cost' => '-1']])
        ->call('save')
        ->assertHasErrors(['supplierId', 'lineItems.0.product_id', 'lineItems.0.quantity_ordered', 'lineItems.0.unit_cost']);

    expect(PurchaseOrder::count())->toBe(0);
});

test('the index page can add and remove line items', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('openCreateModal')
        ->assertCount('lineItems', 1)
        ->call('addLineItem')
        ->assertCount('lineItems', 2)
        ->call('removeLineItem', 1)
        ->assertCount('lineItems', 1)
        ->call('removeLineItem', 0)
        ->assertCount('lineItems', 1);
});

test('the index page filters by status and supplier', function () {
    $draft = createOrder($this);
    $submitted = $this->service->submit(createOrder($this));

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->assertSee($draft->po_number)
        ->assertSee($submitted->po_number)
        ->set('filterStatus', PurchaseOrder::STATUS_SUBMITTED)
        ->assertSee($submitted->po_number)
        ->assertDontSee($draft->po_number)
        ->set('filterStatus', '')
        ->set('search', $draft->po_number)
        ->assertSee($draft->po_number)
        ->assertDontSee($submitted->po_number);
});

test('the index page runs the submit approve and cancel workflow actions', function () {
    $order = createOrder($this);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('submitOrder', $order->id)
        ->call('approveOrder', $order->id);

    expect($order->refresh()->status)->toBe(PurchaseOrder::STATUS_APPROVED);

    $other = createOrder($this);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('cancelOrder', $other->id);

    expect($other->refresh()->status)->toBe(PurchaseOrder::STATUS_CANCELLED);
});

test('the detail page shows the order and receives goods through the modal', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $itemA = $order->items->firstWhere('product_id', $this->productA->id);
    $itemB = $order->items->firstWhere('product_id', $this->productB->id);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-order', ['purchaseOrder' => $order])
        ->assertSee($order->po_number)
        ->assertSee('Approved')
        ->call('openReceiveModal')
        ->assertSet('showReceiveModal', true)
        ->set("receiveQuantities.{$itemA->id}", '10')
        ->set("receiveQuantities.{$itemB->id}", '4')
        ->call('receive')
        ->assertHasNoErrors()
        ->assertSet('showReceiveModal', false);

    expect($order->refresh()->status)->toBe(PurchaseOrder::STATUS_RECEIVED)
        ->and($this->productA->refresh()->current_stock)->toBe(10)
        ->and($this->productB->refresh()->current_stock)->toBe(9);
});

test('the detail page surfaces an over receiving error without touching stock', function () {
    $order = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $order->load('items');

    $itemA = $order->items->firstWhere('product_id', $this->productA->id);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-order', ['purchaseOrder' => $order])
        ->call('openReceiveModal')
        ->set("receiveQuantities.{$itemA->id}", '999')
        ->call('receive')
        ->assertSet('showReceiveModal', true);

    expect($this->productA->refresh()->current_stock)->toBe(0)
        ->and(InventoryTransaction::count())->toBe(0);
});

test('the detail page is reachable over http', function () {
    $order = createOrder($this);

    $this->actingAs($this->admin)
        ->get(route('purchase-orders.show', $order))
        ->assertOk()
        ->assertSee($order->po_number);
});

// ─── Dashboard metrics ────────────────────────────────────────────────────────

test('the service reports purchase order dashboard metrics', function () {
    // Open draft.
    createOrder($this);

    // Awaiting approval.
    $this->service->submit(createOrder($this));

    // Approved and pending delivery.
    $approved = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);

    // Overdue approved order.
    $overdue = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $overdue->update(['expected_delivery_date' => Carbon::now()->subDays(3)->toDateString()]);

    // Fully received order.
    $received = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $received->load('items');
    $this->service->receive(
        $received,
        $received->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all(),
        $this->admin,
    );

    $metrics = $this->service->dashboardMetrics();

    expect($metrics['open_orders'])->toBe(4)
        ->and($metrics['awaiting_approval'])->toBe(1)
        ->and($metrics['pending_deliveries'])->toBe(2)
        ->and($metrics['overdue_deliveries'])->toBe(1)
        ->and($metrics['recently_received'])->toHaveCount(1)
        ->and($metrics['recently_received']->first()->id)->toBe($received->id)
        ->and($metrics['open_value'])->toBe(800.00);

    expect($approved->refresh()->isOverdue())->toBeFalse()
        ->and($overdue->refresh()->isOverdue())->toBeTrue();
});

test('the dashboard exposes purchase order metrics to admins', function () {
    $this->service->submit(createOrder($this));

    Livewire::actingAs($this->admin)
        ->test('pages::dashboard')
        ->assertSet('showPurchaseOrders', true)
        ->assertSet('openPurchaseOrders', 1)
        ->assertSet('purchaseOrdersAwaitingApproval', 1)
        ->assertSet('pendingDeliveries', 0)
        ->assertSet('overdueDeliveries', 0)
        ->assertSee('Open Purchase Orders');
});

test('the dashboard hides purchase order metrics from suppliers', function () {
    // Suppliers must not see the organisation-wide purchase order pipeline. Since
    // Phase 3.3 they do see their own supplier-scoped open order count, so this
    // asserts on the internal-only widgets rather than the shared card label.
    Livewire::actingAs(makeSupplierUser($this->supplier))
        ->test('pages::dashboard')
        ->assertSet('showPurchaseOrders', false)
        ->assertSet('openPurchaseOrders', 0)
        ->assertSet('purchaseOrdersAwaitingApproval', 0)
        ->assertDontSee('Recently Received Orders')
        ->assertDontSee('committed')
        ->assertDontSee('Approved, not fully received');
});

// ─── Reports ──────────────────────────────────────────────────────────────────

test('the purchase order summary report aggregates orders', function () {
    $received = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $received->load('items');
    $this->service->receive(
        $received,
        $received->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all(),
        $this->admin,
    );

    $this->service->cancel(createOrder($this));

    $reports = app(ReportService::class);
    $summary = $reports->purchaseOrderSummary(new ReportFilters);

    expect($summary['order_count'])->toBe(2)
        ->and($summary['received_orders'])->toBe(1)
        ->and($summary['cancelled_orders'])->toBe(1)
        ->and($summary['units_ordered'])->toBe(28)
        ->and($summary['units_received'])->toBe(14)
        ->and($summary['total_value'])->toBe(400.00)
        ->and($summary['received_value'])->toBe(200.00);

    expect($reports->purchaseOrderQuery(new ReportFilters)->count())->toBe(2);
});

test('the outstanding orders report only lists undelivered open orders', function () {
    $open = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $open->load('items');
    $this->service->receive($open, [$open->items->first()->id => 2], $this->admin);

    $complete = $this->service->approve($this->service->submit(createOrder($this)), $this->admin);
    $complete->load('items');
    $this->service->receive(
        $complete,
        $complete->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all(),
        $this->admin,
    );

    $reports = app(ReportService::class);
    $rows = $reports->outstandingOrderQuery(new ReportFilters)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($open->id);

    $summary = $reports->outstandingOrderSummary(new ReportFilters);

    expect($summary['order_count'])->toBe(1)
        ->and($summary['units_outstanding'])->toBe(12)
        ->and($summary['outstanding_value'])->toBe(180.00);
});

test('the supplier purchase history report rolls up per supplier', function () {
    $otherSupplier = Supplier::factory()->create();
    $otherProduct = Product::factory()->create([
        'supplier_id' => $otherSupplier->id,
        'category_id' => $this->category->id,
        'cost_price' => 5.00,
    ]);

    createOrder($this);

    $this->service->create(
        ['supplier_id' => $otherSupplier->id, 'order_date' => Carbon::now()->toDateString()],
        [['product_id' => $otherProduct->id, 'quantity_ordered' => 3, 'unit_cost' => 5.00]],
        $this->admin,
    );

    $reports = app(ReportService::class);
    $rows = $reports->supplierPurchaseQuery(new ReportFilters)->get();

    expect($rows)->toHaveCount(2);

    $top = $rows->first();
    expect($top->id)->toBe($this->supplier->id)
        ->and((int) $top->order_count)->toBe(1)
        ->and((float) $top->total_value)->toBe(200.00)
        ->and((int) $top->units_ordered)->toBe(14);

    $summary = $reports->supplierPurchaseSummary(new ReportFilters);

    expect($summary['supplier_count'])->toBe(2)
        ->and($summary['order_count'])->toBe(2)
        ->and($summary['total_value'])->toBe(215.00)
        ->and($summary['top_supplier'])->toBe($this->supplier->name);
});

test('the reports page renders the purchase order report tabs', function () {
    $order = $this->service->submit(createOrder($this));

    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'purchase_orders')
        ->assertSet('reportType', 'purchase_orders')
        ->assertSee('Purchase Order Summary')
        ->assertSee($order->po_number)
        ->call('selectReport', 'outstanding_orders')
        ->assertSet('reportType', 'outstanding_orders')
        ->assertSee($order->po_number)
        ->call('selectReport', 'supplier_purchases')
        ->assertSet('reportType', 'supplier_purchases')
        ->assertSee($this->supplier->name);
});

test('the purchase order reports can be exported as csv', function () {
    $order = $this->service->submit(createOrder($this));

    $exporter = app(ReportExporter::class);

    foreach (['purchase_orders', 'outstanding_orders', 'supplier_purchases'] as $type) {
        $response = $exporter->csv($type, new ReportFilters);

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        expect($response->headers->get('content-disposition'))->toContain($type)
            ->and($csv)->toContain($type === 'supplier_purchases' ? $this->supplier->name : $order->po_number)
            ->and($csv)->toContain('TOTALS');
    }
});

test('the reports page streams purchase order csv downloads', function () {
    $this->service->submit(createOrder($this));

    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'purchase_orders')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

test('the purchase order reports can be exported as pdf', function () {
    $this->service->submit(createOrder($this));

    $response = app(ReportExporter::class)->pdf('purchase_orders', new ReportFilters, 'Test Operator');

    ob_start();
    $response->sendContent();
    $pdf = (string) ob_get_clean();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdf)->toStartWith('%PDF');
});

// ─── Model helpers ────────────────────────────────────────────────────────────

test('the model reports receiving progress and overdue state', function () {
    $order = PurchaseOrder::factory()->approved()->create([
        'supplier_id' => $this->supplier->id,
        'expected_delivery_date' => Carbon::now()->subDay()->toDateString(),
    ]);

    PurchaseOrderItem::factory()->for($order)->create([
        'product_id' => $this->productA->id,
        'quantity_ordered' => 10,
        'quantity_received' => 5,
        'unit_cost' => 10.00,
        'line_total' => 100.00,
    ]);

    $order->load('items');

    expect($order->totalOrdered())->toBe(10)
        ->and($order->totalReceived())->toBe(5)
        ->and($order->receivedPercentage())->toBe(50.0)
        ->and($order->isOverdue())->toBeTrue()
        ->and($order->isReceivable())->toBeTrue()
        ->and($order->isEditable())->toBeFalse()
        ->and($order->statusLabel())->toBe('Approved')
        ->and($order->canTransitionTo(PurchaseOrder::STATUS_RECEIVED))->toBeTrue()
        ->and($order->canTransitionTo(PurchaseOrder::STATUS_DRAFT))->toBeFalse();
});

test('received and cancelled orders are never flagged overdue', function () {
    $received = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $this->supplier->id,
        'expected_delivery_date' => Carbon::now()->subDays(10)->toDateString(),
    ]);

    $cancelled = PurchaseOrder::factory()->cancelled()->create([
        'supplier_id' => $this->supplier->id,
        'expected_delivery_date' => Carbon::now()->subDays(10)->toDateString(),
    ]);

    expect($received->isOverdue())->toBeFalse()
        ->and($cancelled->isOverdue())->toBeFalse()
        ->and($received->isOpen())->toBeFalse()
        ->and($cancelled->isOpen())->toBeFalse();
});

// ─── Seeders ──────────────────────────────────────────────────────────────────

test('the main database seeder grants the purchase order permissions', function () {
    Role::query()->delete();
    Permission::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(DatabaseSeeder::class);

    $poPermissions = [
        'view purchase orders',
        'manage purchase orders',
        'approve purchase orders',
        'receive purchase orders',
    ];

    foreach ($poPermissions as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
        expect(Role::findByName('Admin')->hasPermissionTo($permission))->toBeTrue();
    }

    $staff = Role::findByName('Staff');

    expect($staff->hasPermissionTo('view purchase orders'))->toBeTrue()
        ->and($staff->hasPermissionTo('manage purchase orders'))->toBeTrue()
        ->and($staff->hasPermissionTo('receive purchase orders'))->toBeTrue()
        ->and($staff->hasPermissionTo('approve purchase orders'))->toBeFalse()
        ->and(Role::findByName('Supplier')->permissions)->toBeEmpty();
});

test('the purchase order permissions seeder is idempotent', function () {
    (new PurchaseOrderPermissionsSeeder)->run();
    (new PurchaseOrderPermissionsSeeder)->run();

    expect(Permission::where('name', 'view purchase orders')->count())->toBe(1)
        ->and(Role::findByName('Staff')->hasPermissionTo('receive purchase orders'))->toBeTrue();
});
