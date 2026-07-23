<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductIdentificationService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('generates unique identifier upon creation', function () {
    $category = Category::create(['name' => 'Test Category']);
    $supplier = Supplier::create(['name' => 'Test Supplier', 'contact_email' => 'test@supplier.com', 'status' => 'active']);

    $product = tap(new Product([
        'sku' => 'TEST-SKU-1',
        'name' => 'Test Product',
        'cost_price' => 10.00,
        'selling_price' => 20.00,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
        'category_id' => $category->id,
        'supplier_id' => $supplier->id,
    ]))->save();

    expect($product->identifier)->not->toBeEmpty();
    expect($product->identifier)->toStartWith('PRD');
    expect(strlen($product->identifier))->toBeGreaterThanOrEqual(9);
});

test('identifier is unique', function () {
    $category = Category::create(['name' => 'Test Category']);
    $supplier = Supplier::create(['name' => 'Test Supplier', 'contact_email' => 'test2@supplier.com', 'status' => 'active']);

    $product1 = Product::create([
        'sku' => 'SKU-A',
        'name' => 'A',
        'category_id' => $category->id,
        'supplier_id' => $supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
    ]);

    $product2 = Product::create([
        'sku' => 'SKU-B',
        'name' => 'B',
        'category_id' => $category->id,
        'supplier_id' => $supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
    ]);

    expect($product1->identifier)->not->toEqual($product2->identifier);
});

test('barcode rendering service returns svg', function () {
    $service = app(ProductIdentificationService::class);
    $svg = $service->generateBarcodeSvg('PRD123456');

    expect($svg)->toContain('<svg');
    expect($svg)->toContain('PRD123456'); // The identifier should be in the generated svg or it should be a valid SVG. Barcode generator doesn't embed text by default for Code128, but it generates an SVG tag.
});

test('qr rendering service returns svg', function () {
    $service = app(ProductIdentificationService::class);
    $svg = $service->generateQrCodeSvg('PRD123456');

    expect($svg)->toContain('<svg');
    expect($svg)->toContain('currentColor'); // We replaced black with currentColor
});

test('can search products by identifier', function () {
    $user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'manage products']);
    $user->givePermissionTo('manage products');

    $category = Category::create(['name' => 'Test Category']);
    $supplier = Supplier::create(['name' => 'Test Supplier', 'contact_email' => 'test3@supplier.com', 'status' => 'active']);

    $product = Product::create([
        'sku' => 'SKU-C',
        'name' => 'C',
        'category_id' => $category->id,
        'supplier_id' => $supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
    ]);

    Livewire::actingAs($user)
        ->test('pages::products')
        ->set('search', $product->identifier)
        ->assertSee($product->name);
});
