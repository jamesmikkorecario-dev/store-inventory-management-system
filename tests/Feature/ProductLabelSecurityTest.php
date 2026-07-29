<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Role::findOrCreate('Supplier');
    Role::findOrCreate('Admin');
    Role::findOrCreate('Staff');
    Permission::findOrCreate('view products');
    Role::findByName('Supplier')->givePermissionTo('view products');
    Role::findByName('Admin')->givePermissionTo('view products');
    Role::findByName('Staff')->givePermissionTo('view products');
});

it('allows supplier to print their own product label', function () {
    $supplier = Supplier::factory()->create();
    $user = User::factory()->create(['supplier_id' => $supplier->id]);
    $user->assignRole('Supplier');
    $product = Product::factory()->create(['supplier_id' => $supplier->id]);

    actingAs($user)
        ->get(route('products.print-label', $product))
        ->assertOk()
        ->assertSee($product->identifier);
});

it('prevents supplier from printing another suppliers product label', function () {
    $supplierA = Supplier::factory()->create();
    $supplierB = Supplier::factory()->create();

    $userA = User::factory()->create(['supplier_id' => $supplierA->id]);
    $userA->assignRole('Supplier');

    $productB = Product::factory()->create(['supplier_id' => $supplierB->id]);

    actingAs($userA)
        ->get(route('products.print-label', $productB))
        ->assertForbidden();
});

it('allows admin to print any product label', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $product = Product::factory()->create();

    actingAs($admin)
        ->get(route('products.print-label', $product))
        ->assertOk();
});

it('allows staff to print any product label', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    $product = Product::factory()->create();

    actingAs($staff)
        ->get(route('products.print-label', $product))
        ->assertOk();
});
