<?php

use App\Models\User;
use App\Models\Supplier;
use App\Models\Category;
use App\Models\Product;
use App\Models\InventoryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Set up standard roles & permissions
    $adminRole = Role::create(['name' => 'Admin']);
    $staffRole = Role::create(['name' => 'Staff']);
    $supplierRole = Role::create(['name' => 'Supplier']);

    Permission::create(['name' => 'manage users']);
    Permission::create(['name' => 'view suppliers']);
    Permission::create(['name' => 'manage suppliers']);
    Permission::create(['name' => 'view categories']);
    Permission::create(['name' => 'manage categories']);
    Permission::create(['name' => 'manage products']);
    Permission::create(['name' => 'manage inventory']);
    Permission::create(['name' => 'view reports']);

    $adminRole->givePermissionTo(Permission::all());

    $this->adminUser = User::factory()->create(['status' => 'active']);
    $this->adminUser->assignRole('Admin');

    $this->supplier = Supplier::create([
        'name' => 'Apex Electronics',
        'email' => 'apex@test.com',
        'status' => 'active',
    ]);

    $this->category = Category::create([
        'name' => 'Microcontrollers',
        'description' => 'Chips and boards',
    ]);
});

test('runtime verification - user crud actions', function () {
    $this->actingAs($this->adminUser);

    // 1. Create User
    Livewire::test('pages::users')
        ->call('openCreateModal')
        ->set('name', 'Staff Operator')
        ->set('email', 'staff@test.com')
        ->set('password', 'password123')
        ->set('roleName', 'Staff')
        ->set('status', 'active')
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = User::where('email', 'staff@test.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Staff Operator');
    expect($user->hasRole('Staff'))->toBeTrue();

    // 2. Edit User
    Livewire::test('pages::users')
        ->call('openEditModal', $user->id)
        ->set('name', 'Staff Operator Updated')
        ->call('saveUser')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Staff Operator Updated');

    // 3. Delete User
    Livewire::test('pages::users')
        ->call('confirmDelete', $user->id)
        ->call('deleteUser')
        ->assertHasNoErrors();

    expect(User::find($user->id))->toBeNull(); // Soft deleted (returns null under default query scopes)
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
});

test('runtime verification - supplier crud actions', function () {
    $this->actingAs($this->adminUser);

    // 1. Create Supplier
    Livewire::test('pages::suppliers')
        ->call('openCreateModal')
        ->set('name', 'Intel Corp')
        ->set('contactPerson', 'Gordon Moore')
        ->set('email', 'intel@test.com')
        ->set('phone', '12345678')
        ->set('address', 'California')
        ->set('status', 'active')
        ->call('saveSupplier')
        ->assertHasNoErrors();

    $supplier = Supplier::where('email', 'intel@test.com')->first();
    expect($supplier)->not->toBeNull();
    expect($supplier->name)->toBe('Intel Corp');

    // 2. Edit Supplier
    Livewire::test('pages::suppliers')
        ->call('openEditModal', $supplier->id)
        ->set('name', 'Intel Corp Inc')
        ->call('saveSupplier')
        ->assertHasNoErrors();

    $supplier->refresh();
    expect($supplier->name)->toBe('Intel Corp Inc');

    // 3. Delete Supplier
    Livewire::test('pages::suppliers')
        ->call('confirmDelete', $supplier->id)
        ->call('deleteSupplier')
        ->assertHasNoErrors();

    expect(Supplier::find($supplier->id))->toBeNull();
});

test('runtime verification - category crud actions', function () {
    $this->actingAs($this->adminUser);

    // 1. Create Category
    Livewire::test('pages::categories')
        ->call('openCreateModal')
        ->set('name', 'Processors')
        ->set('description', 'CPUs')
        ->call('saveCategory')
        ->assertHasNoErrors();

    $category = Category::where('name', 'Processors')->first();
    expect($category)->not->toBeNull();

    // 2. Edit Category
    Livewire::test('pages::categories')
        ->call('openEditModal', $category->id)
        ->set('description', 'CPUs and APUs')
        ->call('saveCategory')
        ->assertHasNoErrors();

    $category->refresh();
    expect($category->description)->toBe('CPUs and APUs');

    // 3. Delete Category
    Livewire::test('pages::categories')
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertHasNoErrors();

    expect(Category::find($category->id))->toBeNull();
});

test('runtime verification - product crud actions', function () {
    $this->actingAs($this->adminUser);

    // 1. Create Product
    Livewire::test('pages::products')
        ->call('openCreateModal')
        ->set('sku', 'PROD-SKU-99')
        ->set('name', 'ESP32 Development Board')
        ->set('categoryId', $this->category->id)
        ->set('supplierId', $this->supplier->id)
        ->set('costPrice', 4.50)
        ->set('sellingPrice', 8.99)
        ->set('minimumStock', 15)
        ->set('status', 'active')
        ->call('saveProduct')
        ->assertHasNoErrors();

    $product = Product::where('sku', 'PROD-SKU-99')->first();
    expect($product)->not->toBeNull();
    expect($product->current_stock)->toBe(0); // Starting stock must be 0

    // 2. Edit Product
    Livewire::test('pages::products')
        ->call('openEditModal', $product->id)
        ->set('name', 'ESP32 NodeMCU')
        ->call('saveProduct')
        ->assertHasNoErrors();

    $product->refresh();
    expect($product->name)->toBe('ESP32 NodeMCU');

    // 3. Delete Product
    Livewire::test('pages::products')
        ->call('confirmDelete', $product->id)
        ->call('deleteProduct')
        ->assertHasNoErrors();

    expect(Product::find($product->id))->toBeNull();
});

test('runtime verification - transactions stock actions', function () {
    $this->actingAs($this->adminUser);

    $product = Product::create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'sku' => 'TX-TEST-SKU',
        'name' => 'Tx Product',
        'cost_price' => 10.00,
        'selling_price' => 15.00,
        'current_stock' => 0,
        'minimum_stock' => 5,
        'status' => 'active',
    ]);

    // 1. Stock In
    Livewire::test('pages::transactions')
        ->call('openLogModal')
        ->set('productId', $product->id)
        ->set('type', 'stock_in')
        ->set('quantity', 50)
        ->set('remarks', 'Purchase Order Receipt')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $product->refresh();
    expect($product->current_stock)->toBe(50);
    expect(InventoryTransaction::where('type', 'stock_in')->where('product_id', $product->id)->count())->toBe(1);

    // 2. Stock Out
    Livewire::test('pages::transactions')
        ->call('openLogModal')
        ->set('productId', $product->id)
        ->set('type', 'stock_out')
        ->set('quantity', 20)
        ->set('remarks', 'Client Sale Dispatch')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $product->refresh();
    expect($product->current_stock)->toBe(30);

    // 3. Stock Adjustment (Subtract)
    Livewire::test('pages::transactions')
        ->call('openLogModal')
        ->set('productId', $product->id)
        ->set('type', 'adjustment')
        ->set('adjustmentDirection', 'subtract')
        ->set('quantity', 5)
        ->set('remarks', 'Audit damaged item write-down')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $product->refresh();
    expect($product->current_stock)->toBe(25);
});

test('runtime verification - reports exports', function () {
    $this->actingAs($this->adminUser);

    // 1. CSV Download
    $csvResponse = Livewire::test('pages::reports')
        ->set('reportType', 'valuation')
        ->call('exportExcel');

    $csvResponse->assertStatus(200);
    $csvResponse->assertFileDownloaded();

    // 2. PDF Download
    $pdfResponse = Livewire::test('pages::reports')
        ->set('reportType', 'low_stock')
        ->call('exportPdf');

    $pdfResponse->assertStatus(200);
    $pdfResponse->assertFileDownloaded();
});
