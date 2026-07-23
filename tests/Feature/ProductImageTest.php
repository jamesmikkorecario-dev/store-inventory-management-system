<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Storage::fake('public');
    $this->category = Category::create(['name' => 'Test Category']);
    $this->supplier = Supplier::create(['name' => 'Test Supplier', 'contact_email' => 'test@supplier.com', 'status' => 'active']);
    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'manage products']);
    Permission::firstOrCreate(['name' => 'view products']);
    $this->user->givePermissionTo(['manage products', 'view products']);
});

test('can upload image when creating product', function () {
    $image = UploadedFile::fake()->image('product.jpg', 400, 400);

    Livewire::actingAs($this->user)
        ->test('pages::products')
        ->call('openCreateModal')
        ->set('sku', 'SKU-IMG-001')
        ->set('name', 'Product With Image')
        ->set('categoryId', $this->category->id)
        ->set('supplierId', $this->supplier->id)
        ->set('costPrice', 10.00)
        ->set('sellingPrice', 20.00)
        ->set('minimumStock', 5)
        ->set('status', 'active')
        ->set('image', $image)
        ->call('saveProduct');

    $product = Product::where('sku', 'SKU-IMG-001')->first();
    expect($product)->not->toBeNull();
    expect($product->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($product->image_path);
});

test('can replace existing product image', function () {
    $oldImage = UploadedFile::fake()->image('old.jpg', 400, 400);
    $oldPath = $oldImage->store('products', 'public');

    $product = Product::create([
        'sku' => 'SKU-REPLACE',
        'name' => 'Replace Image Product',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
        'image_path' => $oldPath,
    ]);

    $newImage = UploadedFile::fake()->image('new.jpg', 400, 400);

    Livewire::actingAs($this->user)
        ->test('pages::products')
        ->call('openEditModal', $product->id)
        ->set('image', $newImage)
        ->call('saveProduct');

    $product->refresh();
    expect($product->image_path)->not->toEqual($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($product->image_path);
});

test('can remove existing product image', function () {
    $image = UploadedFile::fake()->image('remove.jpg', 400, 400);
    $path = $image->store('products', 'public');

    $product = Product::create([
        'sku' => 'SKU-REMOVE',
        'name' => 'Remove Image Product',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
        'image_path' => $path,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::products')
        ->call('openEditModal', $product->id)
        ->call('removeProductImage')
        ->call('saveProduct');

    $product->refresh();
    expect($product->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('product without image returns placeholder url', function () {
    $product = Product::create([
        'sku' => 'SKU-PLACEHOLDER',
        'name' => 'No Image Product',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'cost_price' => 10,
        'selling_price' => 20,
        'minimum_stock' => 5,
        'current_stock' => 10,
        'status' => 'active',
    ]);

    expect($product->image_path)->toBeNull();
    expect($product->imageUrl())->toContain('placehold.co');
});

test('rejects oversized image file', function () {
    $file = UploadedFile::fake()->image('big.jpg', 400, 400)->size(5000); // 5MB

    Livewire::actingAs($this->user)
        ->test('pages::products')
        ->call('openCreateModal')
        ->set('sku', 'SKU-INVALID')
        ->set('name', 'Invalid Image Product')
        ->set('categoryId', $this->category->id)
        ->set('supplierId', $this->supplier->id)
        ->set('costPrice', 10.00)
        ->set('sellingPrice', 20.00)
        ->set('minimumStock', 5)
        ->set('status', 'active')
        ->set('image', $file)
        ->call('saveProduct')
        ->assertHasErrors(['image']);
});
