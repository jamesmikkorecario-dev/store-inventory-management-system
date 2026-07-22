<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->supplier = Supplier::create([
        'name' => 'Test Supplier',
        'email' => 'supplier@test.com',
        'status' => 'active',
    ]);

    $this->category = Category::create([
        'name' => 'Test Category',
    ]);

    $this->user = User::factory()->create();

    $this->product = Product::create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'sku' => 'TEST-SKU-101',
        'name' => 'Test Product',
        'cost_price' => 50.00,
        'selling_price' => 75.00,
        'current_stock' => 0,
        'minimum_stock' => 5,
        'status' => 'active',
    ]);

    $this->service = new InventoryService;
});

test('it can process stock in transaction successfully', function () {
    $tx = $this->service->logTransaction(
        $this->product->id,
        $this->user->id,
        'stock_in',
        15,
        'Log initial stock in'
    );

    // Refresh model state
    $this->product->refresh();

    expect($this->product->current_stock)->toBe(15);
    expect($tx->type)->toBe('stock_in');
    expect($tx->quantity)->toBe(15);
    expect($tx->unit_cost)->toEqual(50.00);
    expect($tx->unit_price)->toEqual(75.00);

    $this->assertDatabaseHas('inventory_transactions', [
        'id' => $tx->id,
        'product_id' => $this->product->id,
        'type' => 'stock_in',
        'quantity' => 15,
    ]);
});

test('it can process stock out transaction successfully', function () {
    // Populate initial stock
    $this->product->update(['current_stock' => 20]);

    $tx = $this->service->logTransaction(
        $this->product->id,
        $this->user->id,
        'stock_out',
        8,
        'Dispatch stock out'
    );

    $this->product->refresh();

    expect($this->product->current_stock)->toBe(12);
    expect($tx->type)->toBe('stock_out');
    expect($tx->quantity)->toBe(8);

    $this->assertDatabaseHas('inventory_transactions', [
        'id' => $tx->id,
        'product_id' => $this->product->id,
        'type' => 'stock_out',
        'quantity' => 8,
    ]);
});

test('it prevents negative stock and throws exception', function () {
    // Stock is initially 0
    expect(function () {
        $this->service->logTransaction(
            $this->product->id,
            $this->user->id,
            'stock_out',
            5,
            'Attempt illegal stock out'
        );
    })->toThrow(Exception::class);

    $this->product->refresh();
    expect($this->product->current_stock)->toBe(0);

    // No transaction was inserted
    expect(InventoryTransaction::count())->toBe(0);
});

test('it can log signed stock adjustments', function () {
    $this->product->update(['current_stock' => 10]);

    // Adjust up
    $tx1 = $this->service->logTransaction(
        $this->product->id,
        $this->user->id,
        'adjustment',
        5,
        'Found extra stock box'
    );

    $this->product->refresh();
    expect($this->product->current_stock)->toBe(15);

    // Adjust down
    $tx2 = $this->service->logTransaction(
        $this->product->id,
        $this->user->id,
        'adjustment',
        -3,
        'Writedown damaged items'
    );

    $this->product->refresh();
    expect($this->product->current_stock)->toBe(12);
});
