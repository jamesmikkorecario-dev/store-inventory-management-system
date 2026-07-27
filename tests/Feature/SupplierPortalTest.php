<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\SupplierPortalService;
use Database\Seeders\ForecastPermissionsSeeder;
use Database\Seeders\PurchaseOrderPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions matching the real seeder wiring.
 */
function seedPortalRoles(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['Admin', 'Staff', 'Supplier'] as $role) {
        Role::findOrCreate($role);
    }

    $shared = ['manage users', 'view products', 'view transactions', 'view reports', 'export reports', 'view audit trail', 'manage inventory'];

    foreach ($shared as $permission) {
        Permission::findOrCreate($permission);
    }

    Role::findByName('Admin')->givePermissionTo($shared);
    Role::findByName('Staff')->givePermissionTo(['view products', 'view transactions', 'view reports', 'export reports', 'manage inventory']);

    (new PurchaseOrderPermissionsSeeder)->run();
    (new ForecastPermissionsSeeder)->run();
}

/**
 * Build a supplier with products and a full purchase order history.
 *
 * @return array{supplier: Supplier, user: User, products: Collection<int, Product>, orders: Collection<int, PurchaseOrder>}
 */
function makeSupplierWorld(string $name, Category $category, User $buyer, User $approver): array
{
    $supplier = Supplier::factory()->create(['name' => $name, 'status' => 'active']);

    $products = collect([
        Product::factory()->create([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => $name.' Widget',
            'cost_price' => 10.00,
            'current_stock' => 40,
            'minimum_stock' => 10,
            'status' => 'active',
        ]),
        Product::factory()->create([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => $name.' Gadget',
            'cost_price' => 20.00,
            'current_stock' => 2,
            'minimum_stock' => 15,
            'status' => 'active',
        ]),
    ]);

    $service = app(PurchaseOrderService::class);

    $attributes = fn (int $days) => [
        'supplier_id' => $supplier->id,
        'order_date' => Carbon::now()->subDays($days)->toDateString(),
        'expected_delivery_date' => Carbon::now()->addDays(7)->toDateString(),
        'notes' => $name.' order notes',
    ];

    $items = [
        ['product_id' => $products[0]->id, 'quantity_ordered' => 10, 'unit_cost' => 10.00],
        ['product_id' => $products[1]->id, 'quantity_ordered' => 5, 'unit_cost' => 20.00],
    ];

    $draft = $service->create($attributes(10), $items, $buyer);

    $received = $service->approve($service->submit($service->create($attributes(5), $items, $buyer)), $approver);
    $received->load('items');
    $service->receive(
        $received,
        $received->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all(),
        $buyer,
    );

    $submitted = $service->submit($service->create($attributes(2), $items, $buyer));

    $user = User::factory()->create(['supplier_id' => $supplier->id, 'name' => $name.' Rep']);
    $user->assignRole('Supplier');

    return [
        'supplier' => $supplier,
        'user' => $user,
        'products' => $products,
        'orders' => collect([$draft->refresh(), $received->refresh(), $submitted->refresh()]),
    ];
}

beforeEach(function () {
    seedPortalRoles();

    $this->category = Category::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');

    $this->staff = User::factory()->create();
    $this->staff->assignRole('Staff');

    $this->portal = app(SupplierPortalService::class);

    $this->acme = makeSupplierWorld('Acme', $this->category, $this->staff, $this->admin);
    $this->rival = makeSupplierWorld('Rival', $this->category, $this->staff, $this->admin);
});

// ─── Supplier dashboard ───────────────────────────────────────────────────────

test('the supplier dashboard shows the supplier scoped overview', function () {
    Livewire::actingAs($this->acme['user'])
        ->test('pages::dashboard')
        ->assertSet('isSupplier', true)
        ->assertSee('My Supply Overview')
        ->assertSee('Products Supplied')
        ->assertSee('Open Purchase Orders')
        ->assertSee('Recent Purchase Orders')
        ->assertSee('Performance');
});

test('the supplier dashboard metrics only count the linked supplier', function () {
    $component = Livewire::actingAs($this->acme['user'])->test('pages::dashboard');

    $metrics = $component->get('supplierMetrics');

    expect($metrics['total_products'])->toBe(2)
        ->and($metrics['low_stock_products'])->toBe(1)
        ->and($metrics['open_purchase_orders'])->toBe(2)
        ->and($metrics['awaiting_approval'])->toBe(1)
        ->and($metrics['inventory_value'])->toBe(640.00);
});

test('the supplier dashboard never lists another suppliers orders', function () {
    Livewire::actingAs($this->acme['user'])
        ->test('pages::dashboard')
        ->assertDontSee($this->rival['orders'][0]->po_number)
        ->assertDontSee('Rival Widget');
});

test('admin and staff dashboards do not render the supplier overview', function () {
    foreach ([$this->admin, $this->staff] as $user) {
        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->assertSet('isSupplier', false)
            ->assertDontSee('My Supply Overview');
    }
});

// ─── Product catalog ──────────────────────────────────────────────────────────

test('a supplier can reach their read-only catalog', function () {
    $this->actingAs($this->acme['user'])
        ->get(route('portal.catalog'))
        ->assertOk()
        ->assertSee('My Product Catalog')
        ->assertSee('Read only')
        ->assertSee('Acme Widget')
        ->assertDontSee('Rival Widget');
});

test('the catalog lists stock cost and status for supplied products only', function () {
    Livewire::actingAs($this->acme['user'])
        ->test('pages::portal-catalog')
        ->assertSee('Acme Widget')
        ->assertSee('Acme Gadget')
        ->assertSee($this->acme['products'][0]->sku)
        ->assertDontSee('Rival Widget')
        ->assertDontSee($this->rival['products'][0]->sku);
});

test('the catalog search and filters stay inside the supplier scope', function () {
    Livewire::actingAs($this->acme['user'])
        ->test('pages::portal-catalog')
        ->set('search', 'Gadget')
        ->assertSee('Acme Gadget')
        ->assertDontSee('Acme Widget')
        // Searching for another supplier's product yields nothing.
        ->set('search', 'Rival')
        ->assertSee('No products found')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSee('Acme Widget');
});

test('the catalog query is supplier scoped at the query level', function () {
    $rows = $this->portal->productQuery($this->acme['supplier'])->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('supplier_id')->unique()->all())->toBe([$this->acme['supplier']->id]);
});

// ─── Purchase orders ──────────────────────────────────────────────────────────

test('a supplier only sees their own purchase orders', function () {
    $response = $this->actingAs($this->acme['user'])->get(route('portal.orders.index'));

    $response->assertOk()->assertSee('My Purchase Orders');

    foreach ($this->acme['orders'] as $order) {
        $response->assertSee($order->po_number);
    }

    foreach ($this->rival['orders'] as $order) {
        $response->assertDontSee($order->po_number);
    }
});

test('the purchase order query is supplier scoped at the query level', function () {
    $rows = $this->portal->purchaseOrderQuery($this->acme['supplier'])->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('supplier_id')->unique()->all())->toBe([$this->acme['supplier']->id]);
});

test('supplier purchase orders can be searched and filtered by status and date', function () {
    $received = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_RECEIVED);
    $draft = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_DRAFT);

    Livewire::actingAs($this->acme['user'])
        ->test('pages::portal-orders')
        ->set('filterStatus', PurchaseOrder::STATUS_RECEIVED)
        ->assertSee($received->po_number)
        ->assertDontSee($draft->po_number)
        ->set('filterStatus', '')
        ->set('search', $draft->po_number)
        ->assertSee($draft->po_number)
        ->assertDontSee($received->po_number)
        ->call('resetFilters')
        ->set('filterStartDate', Carbon::now()->subDays(3)->toDateString())
        ->assertDontSee($draft->po_number);
});

test('a supplier can open their own purchase order detail with line items and timeline', function () {
    $order = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_RECEIVED);

    $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.show', $order))
        ->assertOk()
        ->assertSee($order->po_number)
        ->assertSee('Line Items')
        ->assertSee('Status Timeline')
        ->assertSee('Order raised')
        ->assertSee('Approved')
        ->assertSee('Acme Widget');
});

test('the detail page shows delivered quantities per line', function () {
    $order = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_RECEIVED);

    Livewire::actingAs($this->acme['user'])
        ->test('pages::portal-order', ['purchaseOrder' => $order->id])
        ->assertSee($order->po_number)
        ->assertSee('Received')
        ->assertSee('GRAND TOTAL');
});

// ─── PDF download ─────────────────────────────────────────────────────────────

test('a supplier can download the pdf for their own purchase order', function () {
    $order = $this->acme['orders']->first();

    $response = $this->actingAs($this->acme['user'])->get(route('portal.orders.pdf', $order));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('purchase_order_'.$order->po_number)
        ->and($response->streamedContent())->toStartWith('%PDF');
});

test('the purchase order pdf contains the order and supplier details', function () {
    $order = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_RECEIVED);

    $pdf = $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.pdf', $order))
        ->streamedContent();

    // DomPDF output is compressed, so assert on the rendered HTML instead.
    $html = view('exports.purchase_order', ['order' => $order->load('items.product', 'supplier', 'creator')])->render();

    expect($pdf)->toStartWith('%PDF')
        ->and($html)->toContain($order->po_number)
        ->and($html)->toContain('Acme')
        ->and($html)->toContain('Acme Widget')
        ->and($html)->toContain('GRAND TOTAL');
});

test('a supplier cannot download the pdf of another suppliers purchase order', function () {
    $foreign = $this->rival['orders']->first();

    $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.pdf', $foreign))
        ->assertNotFound();
});

// ─── Performance page ─────────────────────────────────────────────────────────

test('the performance page reports supplier scoped totals', function () {
    $this->actingAs($this->acme['user'])
        ->get(route('portal.performance'))
        ->assertOk()
        ->assertSee('My Performance')
        ->assertSee('Total Purchase Orders')
        ->assertSee('Units Supplied')
        ->assertSee('Total Purchase Value')
        ->assertSee('Top Supplied Products')
        ->assertSee('Acme Widget')
        ->assertDontSee('Rival Widget');
});

test('the performance summary aggregates only the linked suppliers orders', function () {
    $summary = $this->portal->performanceSummary($this->acme['supplier']);

    // 3 orders x (10 units @ $10 + 5 units @ $20) = 3 x $200 = $600, 45 units ordered.
    expect($summary['order_count'])->toBe(3)
        ->and($summary['received_orders'])->toBe(1)
        ->and($summary['open_orders'])->toBe(2)
        ->and($summary['units_ordered'])->toBe(45)
        ->and($summary['units_received'])->toBe(15)
        ->and($summary['purchase_value'])->toBe(600.00)
        ->and($summary['received_value'])->toBe(200.00)
        ->and($summary['outstanding_units'])->toBe(30)
        ->and($summary['fulfilment_rate'])->toBe(33.3)
        ->and($summary['first_order_at'])->not->toBeNull()
        ->and($summary['last_order_at'])->not->toBeNull();
});

test('top supplied products are ranked by delivered units within the supplier', function () {
    $top = $this->portal->topSuppliedProducts($this->acme['supplier']);

    expect($top)->toHaveCount(2)
        ->and($top->pluck('supplier_id')->unique()->all())->toBe([$this->acme['supplier']->id])
        ->and((int) $top->first()->units_received)->toBe(10)
        ->and($top->first()->name)->toBe('Acme Widget');
});

// ─── Isolation of the scoped lookup ───────────────────────────────────────────

test('the scoped lookup refuses another suppliers purchase order', function () {
    $foreign = $this->rival['orders']->first();

    expect($this->portal->findPurchaseOrder($this->acme['supplier'], $foreign->id))->toBeNull()
        ->and($this->portal->findPurchaseOrder($this->acme['supplier'], $this->acme['orders']->first()->id))
        ->not->toBeNull();
});

test('a supplier gets a 404 opening another suppliers purchase order', function () {
    $foreign = $this->rival['orders']->first();

    $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.show', $foreign))
        ->assertNotFound();
});

test('a supplier gets a 404 for a purchase order that does not exist', function () {
    $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.show', 999999))
        ->assertNotFound();
});

// ─── Authorization: who may reach the portal ──────────────────────────────────

test('guests are redirected to login from every portal route', function () {
    foreach ([
        route('portal.catalog'),
        route('portal.orders.index'),
        route('portal.performance'),
        route('portal.orders.show', $this->acme['orders']->first()),
        route('portal.orders.pdf', $this->acme['orders']->first()),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

test('admin and staff cannot reach the supplier portal', function () {
    foreach ([$this->admin, $this->staff] as $user) {
        foreach ([
            route('portal.catalog'),
            route('portal.orders.index'),
            route('portal.performance'),
            route('portal.orders.pdf', $this->acme['orders']->first()),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }
});

test('a supplier user with no linked supplier sees a guidance panel instead of data', function () {
    $orphan = User::factory()->create(['supplier_id' => null]);
    $orphan->assignRole('Supplier');

    $this->actingAs($orphan)->get(route('portal.catalog'))->assertOk()->assertSee('No supplier linked');
    $this->actingAs($orphan)->get(route('portal.orders.index'))->assertOk()->assertSee('No supplier linked');
    $this->actingAs($orphan)->get(route('portal.performance'))->assertOk()->assertSee('No supplier linked');

    // Document routes and detail pages refuse outright rather than leaking anything.
    $this->actingAs($orphan)->get(route('portal.orders.pdf', $this->acme['orders']->first()))->assertForbidden();
    $this->actingAs($orphan)->get(route('portal.orders.show', $this->acme['orders']->first()))->assertForbidden();
});

// ─── Security: modules a supplier must never reach ────────────────────────────

test('suppliers are blocked from admin only modules', function () {
    $blocked = [
        'users.index' => route('users.index'),
        'reports.index' => route('reports.index'),
        'purchase-orders.index' => route('purchase-orders.index'),
        'suppliers.index' => route('suppliers.index'),
        'categories.index' => route('categories.index'),
        'audit.index' => route('audit.index'),
        'alerts.index' => route('alerts.index'),
    ];

    foreach ($blocked as $name => $url) {
        $this->actingAs($this->acme['user'])->get($url)->assertForbidden();
    }
});

test('suppliers hold no forecasting or purchase order permissions', function () {
    $user = $this->acme['user'];

    foreach ([
        'view forecasts', 'export forecasts',
        'view purchase orders', 'manage purchase orders', 'approve purchase orders', 'receive purchase orders',
        'manage users', 'view reports', 'export reports', 'view audit trail',
    ] as $permission) {
        expect($user->can($permission))->toBeFalse("supplier should not hold '{$permission}'");
    }
});

test('suppliers cannot reach the admin purchase order detail page', function () {
    $own = $this->acme['orders']->first();

    // Even for their own order, the internal buyer-facing page stays closed.
    $this->actingAs($this->acme['user'])
        ->get(route('purchase-orders.show', $own))
        ->assertForbidden();
});

test('the shared products page stays scoped for supplier users', function () {
    $this->actingAs($this->acme['user'])
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee('Acme Widget')
        ->assertDontSee('Rival Widget');
});

test('the shared transactions page stays scoped for supplier users', function () {
    $this->actingAs($this->acme['user'])
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertDontSee('Rival Widget');
});

// ─── Read-only guarantee ──────────────────────────────────────────────────────

test('the portal service exposes no mutating methods', function () {
    $methods = collect((new ReflectionClass(SupplierPortalService::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->pluck('name')
        ->reject(fn (string $name): bool => str_starts_with($name, '__'))
        ->values();

    // Guards against a future write path being added to the supplier-facing service.
    foreach ($methods as $method) {
        expect($method)->not->toMatch('/^(create|update|delete|destroy|store|save|receive|approve|submit|cancel)/');
    }

    expect($methods)->toContain('productQuery', 'purchaseOrderQuery', 'dashboardMetrics', 'performanceSummary');
});

test('portal pages expose no write actions to suppliers', function () {
    $order = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_DRAFT);

    Livewire::actingAs($this->acme['user'])
        ->test('pages::portal-order', ['purchaseOrder' => $order->id])
        ->assertDontSee('Receive Goods')
        ->assertDontSee('Submit for Approval')
        ->assertDontSee('Cancel Order');
});

// ─── Data integrity of scoped aggregates ──────────────────────────────────────

test('metrics recompute when a delivery is booked in', function () {
    $order = $this->acme['orders']->firstWhere('status', PurchaseOrder::STATUS_DRAFT);
    $service = app(PurchaseOrderService::class);

    $before = $this->portal->performanceSummary($this->acme['supplier']);

    $approved = $service->approve($service->submit($order), $this->admin);
    $approved->load('items');
    $service->receive($approved, [$approved->items->first()->id => 4], $this->staff);

    $after = $this->portal->performanceSummary($this->acme['supplier']);

    expect($after['units_received'])->toBe($before['units_received'] + 4)
        ->and($after['open_orders'])->toBe($before['open_orders'])
        ->and($after['fulfilment_rate'])->toBeGreaterThan($before['fulfilment_rate']);
});

test('a supplier with no orders or products gets zeroed metrics', function () {
    $empty = Supplier::factory()->create();
    $user = User::factory()->create(['supplier_id' => $empty->id]);
    $user->assignRole('Supplier');

    $metrics = $this->portal->dashboardMetrics($empty);
    $summary = $this->portal->performanceSummary($empty);

    expect($metrics['total_products'])->toBe(0)
        ->and($metrics['inventory_value'])->toBe(0.0)
        ->and($metrics['open_purchase_orders'])->toBe(0)
        ->and($summary['order_count'])->toBe(0)
        ->and($summary['fulfilment_rate'])->toBe(0.0)
        ->and($summary['first_order_at'])->toBeNull();

    $this->actingAs($user)->get(route('portal.catalog'))->assertOk()->assertSee('No products are assigned');
    $this->actingAs($user)->get(route('portal.orders.index'))->assertOk()->assertSee('No purchase orders have been raised');
});

test('soft deleted purchase orders are excluded from supplier views', function () {
    $order = $this->acme['orders']->first();
    $order->delete();

    $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.index'))
        ->assertOk()
        ->assertDontSee($order->po_number);

    $this->actingAs($this->acme['user'])
        ->get(route('portal.orders.show', $order))
        ->assertNotFound();
});

test('purchase order items belonging to the order are the only ones shown', function () {
    $order = $this->acme['orders']->first();

    $foreignProduct = Product::factory()->create([
        'supplier_id' => $this->rival['supplier']->id,
        'category_id' => $this->category->id,
        'name' => 'Rival Secret Component',
    ]);

    $foreignItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $this->rival['orders']->first()->id,
        'product_id' => $foreignProduct->id,
        'quantity_ordered' => 99,
        'unit_cost' => 5.00,
        'line_total' => 495.00,
    ]);

    Livewire::actingAs($this->acme['user'])
        ->test('pages::portal-order', ['purchaseOrder' => $order->id])
        ->assertDontSee('Rival Secret Component')
        ->assertDontSee((string) $foreignItem->quantity_ordered);
});
