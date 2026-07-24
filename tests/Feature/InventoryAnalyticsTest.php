<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryAnalyticsService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->category = Category::factory()->create();
    $this->supplier = Supplier::factory()->create();
    $this->adminRole = Role::firstOrCreate(['name' => 'Admin']);
    $this->staffRole = Role::firstOrCreate(['name' => 'Staff']);
    $this->supplierRole = Role::firstOrCreate(['name' => 'Supplier']);

    $this->adminUser = User::factory()->create();
    $this->adminUser->assignRole($this->adminRole);

    $this->analyticsService = app(InventoryAnalyticsService::class);
});

// ─── Inventory Value Calculations ─────────────────────────────────────────────

test('it calculates total inventory value correctly', function () {
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 10,
        'cost_price' => 25.00,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 5,
        'cost_price' => 50.00,
        'status' => 'active',
    ]);

    $value = $this->analyticsService->getTotalInventoryValue();

    // 10 * 25 + 5 * 50 = 500
    expect($value)->toBe(500.0);
});

test('it calculates inventory value scoped to supplier', function () {
    $otherSupplier = Supplier::factory()->create();

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 10,
        'cost_price' => 20.00,
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $otherSupplier->id,
        'current_stock' => 5,
        'cost_price' => 100.00,
    ]);

    $value = $this->analyticsService->getTotalInventoryValue($this->supplier->id);

    expect($value)->toBe(200.0);
});

test('it returns zero inventory value when no products exist', function () {
    $value = $this->analyticsService->getTotalInventoryValue();

    expect($value)->toBe(0.0);
});

// ─── Low Stock Counts ─────────────────────────────────────────────────────────

test('it counts low stock products correctly', function () {
    // Low stock (current <= minimum but > 0)
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 3,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    // Healthy stock
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 50,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    // Out of stock (excluded from low stock count)
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 0,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    $count = $this->analyticsService->getLowStockCount();

    expect($count)->toBe(1);
});

// ─── Out of Stock Counts ──────────────────────────────────────────────────────

test('it counts out of stock products correctly', function () {
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 0,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 0,
        'minimum_stock' => 5,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 15,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    $count = $this->analyticsService->getOutOfStockCount();

    expect($count)->toBe(2);
});

// ─── Active Products ──────────────────────────────────────────────────────────

test('it counts active products correctly', function () {
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'status' => 'inactive',
    ]);

    $count = $this->analyticsService->getActiveProductCount();

    expect($count)->toBe(2);
});

// ─── Top Moving Products ──────────────────────────────────────────────────────

test('it returns top moving products by transaction volume', function () {
    $productA = Product::factory()->create([
        'name' => 'Product A',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 50,
    ]);

    $productB = Product::factory()->create([
        'name' => 'Product B',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 30,
    ]);

    // Product A: 5 transactions
    InventoryTransaction::factory()->count(5)->create([
        'product_id' => $productA->id,
        'user_id' => $this->adminUser->id,
        'transaction_date' => now()->subDays(5),
    ]);

    // Product B: 2 transactions
    InventoryTransaction::factory()->count(2)->create([
        'product_id' => $productB->id,
        'user_id' => $this->adminUser->id,
        'transaction_date' => now()->subDays(3),
    ]);

    $topMovers = $this->analyticsService->getTopMovingProducts(30);

    expect($topMovers)->toHaveCount(2)
        ->and($topMovers->first()->name)->toBe('Product A')
        ->and($topMovers->first()->total_movements)->toBe(5);
});

test('it returns empty collection when no transactions exist', function () {
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    $topMovers = $this->analyticsService->getTopMovingProducts(30);

    expect($topMovers)->toHaveCount(0);
});

// ─── Slow Moving Products ─────────────────────────────────────────────────────

test('it returns slow moving products', function () {
    $slowProduct = Product::factory()->create([
        'name' => 'Slow Product',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 20,
        'status' => 'active',
    ]);

    $activeProduct = Product::factory()->create([
        'name' => 'Active Product',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 15,
        'status' => 'active',
    ]);

    // Only the active product has a recent transaction
    InventoryTransaction::factory()->create([
        'product_id' => $activeProduct->id,
        'user_id' => $this->adminUser->id,
        'transaction_date' => now(),
    ]);

    $slowMovers = $this->analyticsService->getSlowMovingProducts(30);

    // Slow product (no transactions) should appear first
    expect($slowMovers)->toHaveCount(2)
        ->and($slowMovers->first()->name)->toBe('Slow Product')
        ->and($slowMovers->first()->days_since_last_transaction)->toBeNull();
});

// ─── Inventory Health ─────────────────────────────────────────────────────────

test('it calculates inventory health breakdown', function () {
    // Healthy: stock > minimum
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 50,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 30,
        'minimum_stock' => 5,
        'status' => 'active',
    ]);

    // Low stock: current <= minimum and > 0
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 3,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    // Out of stock: current = 0
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 0,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    $health = $this->analyticsService->getInventoryHealth();

    expect($health['healthy'])->toBe(2)
        ->and($health['low_stock'])->toBe(1)
        ->and($health['out_of_stock'])->toBe(1)
        ->and($health['total'])->toBe(4)
        ->and($health['healthy_pct'])->toBe(50.0)
        ->and($health['low_stock_pct'])->toBe(25.0)
        ->and($health['out_of_stock_pct'])->toBe(25.0);
});

test('it returns zero percentages when no active products exist', function () {
    $health = $this->analyticsService->getInventoryHealth();

    expect($health['total'])->toBe(0)
        ->and($health['healthy_pct'])->toBe(0.0)
        ->and($health['low_stock_pct'])->toBe(0.0)
        ->and($health['out_of_stock_pct'])->toBe(0.0);
});

// ─── Stock Movement Trend ─────────────────────────────────────────────────────

test('it returns stock movement trend data', function () {
    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    InventoryTransaction::factory()->stockIn()->create([
        'product_id' => $product->id,
        'user_id' => $this->adminUser->id,
        'quantity' => 10,
        'transaction_date' => now(),
    ]);

    InventoryTransaction::factory()->stockOut()->create([
        'product_id' => $product->id,
        'user_id' => $this->adminUser->id,
        'quantity' => 5,
        'transaction_date' => now(),
    ]);

    $trend = $this->analyticsService->getStockMovementTrend(7);

    expect($trend)->toHaveKeys(['labels', 'stock_in', 'stock_out'])
        ->and($trend['labels'])->toHaveCount(8) // 7 days ago through today = 8 days
        ->and(array_sum($trend['stock_in']))->toBe(10)
        ->and(array_sum($trend['stock_out']))->toBe(5);
});

// ─── Activity Overview ────────────────────────────────────────────────────────

test('it returns inventory activity overview data', function () {
    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    InventoryTransaction::factory()->count(3)->create([
        'product_id' => $product->id,
        'user_id' => $this->adminUser->id,
        'transaction_date' => now(),
    ]);

    $overview = $this->analyticsService->getInventoryActivityOverview(7);

    expect($overview)->toHaveKeys(['labels', 'activity'])
        ->and($overview['labels'])->toHaveCount(8)
        ->and(array_sum($overview['activity']))->toBe(3);
});

// ─── Dashboard Rendering ─────────────────────────────────────────────────────

test('admin can see analytics section on dashboard', function () {
    $this->actingAs($this->adminUser);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Inventory Analytics')
        ->assertSee('Inventory Health')
        ->assertSee('Stock Movement Trend')
        ->assertSee('Inventory Activity Overview')
        ->assertSee('Top Moving Products')
        ->assertSee('Slow Moving Products');
});

test('staff can see analytics section on dashboard', function () {
    $staffUser = User::factory()->create();
    $staffUser->assignRole($this->staffRole);

    $this->actingAs($staffUser);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Inventory Analytics')
        ->assertSee('Inventory Health');
});

test('supplier can see analytics section on dashboard', function () {
    $supplierUser = User::factory()->create(['supplier_id' => $this->supplier->id]);
    $supplierUser->assignRole($this->supplierRole);

    $this->actingAs($supplierUser);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Inventory Analytics')
        ->assertSee('Inventory Health');
});

test('dashboard displays inventory health widget with correct counts', function () {
    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 50,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    Product::factory()->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 0,
        'minimum_stock' => 10,
        'status' => 'active',
    ]);

    $this->actingAs($this->adminUser);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Healthy Products')
        ->assertSee('Out of Stock');
});

// ─── Filter Period Switching ──────────────────────────────────────────────────

test('dashboard defaults to 30 day period', function () {
    $this->actingAs($this->adminUser);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('30 Days');
});

test('period filter buttons are rendered', function () {
    $this->actingAs($this->adminUser);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('7 Days')
        ->assertSee('30 Days')
        ->assertSee('90 Days');
});
