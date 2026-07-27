<?php

use App\Livewire\Alerts\Index;
use App\Models\Category;
use App\Models\LowStockNotification;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->category = Category::create(['name' => 'Test Category']);
    $this->supplier = Supplier::create(['name' => 'Test Supplier', 'contact_email' => 'test@supplier.com', 'status' => 'active']);

    $this->adminRole = Role::firstOrCreate(['name' => 'Admin']);
    $this->staffRole = Role::firstOrCreate(['name' => 'Staff']);
    $this->supplierRole = Role::firstOrCreate(['name' => 'Supplier']);

    $this->adminUser = User::factory()->create();
    $this->adminUser->assignRole($this->adminRole);
});

test('it creates a low stock notification when stock is below threshold', function () {
    $product = Product::create([
        'sku' => 'SKU-001',
        'name' => 'Product 1',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5, // Below minimum
        'status' => 'active',
    ]);

    $notification = LowStockNotification::where('product_id', $product->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->current_stock)->toBe(5);
    expect($notification->threshold)->toBe(10);
    expect($notification->severity)->toBe('low');
    expect($notification->resolved_at)->toBeNull();
});

test('it creates a critical stock notification when stock is zero', function () {
    $product = Product::create([
        'sku' => 'SKU-002',
        'name' => 'Product 2',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 0, // Critical
        'status' => 'active',
    ]);

    $notification = LowStockNotification::where('product_id', $product->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->severity)->toBe('critical');
});

test('it prevents duplicate active notifications but updates existing one', function () {
    $product = Product::create([
        'sku' => 'SKU-003',
        'name' => 'Product 3',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    expect(LowStockNotification::count())->toBe(1);

    // Update stock but still low
    $product->update(['current_stock' => 2]);

    expect(LowStockNotification::count())->toBe(1); // Still 1

    $notification = LowStockNotification::first();
    expect($notification->current_stock)->toBe(2);
});

test('it automatically resolves notification when stock recovers', function () {
    $product = Product::create([
        'sku' => 'SKU-004',
        'name' => 'Product 4',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    expect(LowStockNotification::whereNull('resolved_at')->count())->toBe(1);

    // Recover stock
    $product->update(['current_stock' => 15]);

    expect(LowStockNotification::whereNull('resolved_at')->count())->toBe(0);

    $notification = LowStockNotification::first();
    expect($notification->resolved_at)->not->toBeNull();
});

test('it regenerates a new notification if stock drops again after recovery', function () {
    $product = Product::create([
        'sku' => 'SKU-005',
        'name' => 'Product 5',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    // Recover stock
    $product->update(['current_stock' => 15]);

    // Drop again
    $product->update(['current_stock' => 3]);

    expect(LowStockNotification::count())->toBe(2);
    expect(LowStockNotification::whereNull('resolved_at')->count())->toBe(1);
});

test('unread count and component rendering', function () {
    Product::create([
        'sku' => 'SKU-006',
        'name' => 'Product 6',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    Livewire::actingAs($this->adminUser)
        ->test(Index::class)
        ->assertSee('Product 6')
        ->assertSee('Stock is at');
});

test('it can mark notification as read', function () {
    $product = Product::create([
        'sku' => 'SKU-007',
        'name' => 'Product 7',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    $notification = LowStockNotification::first();

    Livewire::actingAs($this->adminUser)
        ->test(Index::class)
        ->call('markAsRead', $notification->id);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('it can mark all notifications as read', function () {
    Product::create([
        'sku' => 'SKU-008',
        'name' => 'Product 8',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    Product::create([
        'sku' => 'SKU-009',
        'name' => 'Product 9',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 0,
        'status' => 'active',
    ]);

    Livewire::actingAs($this->adminUser)
        ->test(Index::class)
        ->call('markAllAsRead');

    expect(LowStockNotification::whereNull('read_at')->count())->toBe(0);
});

test('dashboard widget renders latest active alerts', function () {
    $product = Product::create([
        'sku' => 'SKU-DASH-1',
        'name' => 'Dash Product 1',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 10,
        'current_stock' => 5,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->adminUser)->get(route('dashboard'));
    $response->assertSee('Low Stock Alerts');
    $response->assertSee('Dash Product 1');
    $response->assertSee('View all');
});

test('admin can access alerts module', function () {
    $response = $this->actingAs($this->adminUser)->get(route('alerts.index'));
    $response->assertStatus(200);
});

test('staff can access alerts module', function () {
    $staffUser = User::factory()->create();
    $staffUser->assignRole($this->staffRole);

    $response = $this->actingAs($staffUser)->get(route('alerts.index'));
    $response->assertStatus(200);
});

test('supplier cannot access alerts module', function () {
    $supplierUser = User::factory()->create(['supplier_id' => $this->supplier->id]);
    $supplierUser->assignRole($this->supplierRole);

    $response = $this->actingAs($supplierUser)->get(route('alerts.index'));
    $response->assertStatus(403);
});

test('supplier cannot see alerts in sidebar', function () {
    $supplierUser = User::factory()->create(['supplier_id' => $this->supplier->id]);
    $supplierUser->assignRole($this->supplierRole);

    $response = $this->actingAs($supplierUser)->get(route('dashboard'));
    $response->assertStatus(200);
    $response->assertDontSee('href="'.route('alerts.index').'"', false);
    $response->assertDontSee('>Alerts<', false);
});
