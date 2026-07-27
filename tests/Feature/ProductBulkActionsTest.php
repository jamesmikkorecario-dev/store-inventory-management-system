<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\LowStockNotification;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductBulkActionService;
use Database\Seeders\BulkOperationsPermissionsSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permissions = collect([
        'view products',
        'manage products',
        'import products',
        'bulk manage products',
        'export catalog',
    ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name]));

    Role::firstOrCreate(['name' => 'Admin'])->givePermissionTo($permissions->all());
    Role::firstOrCreate(['name' => 'Staff'])->givePermissionTo([
        'view products',
        'import products',
        'bulk manage products',
        'export catalog',
    ]);
    Role::firstOrCreate(['name' => 'Supplier']);

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('Admin');

    $this->staff = User::factory()->create(['status' => 'active']);
    $this->staff->assignRole('Staff');

    $this->category = Category::factory()->create(['name' => 'Origin Category']);
    $this->targetCategory = Category::factory()->create(['name' => 'Target Category']);
    $this->supplier = Supplier::factory()->create(['name' => 'Origin Supply', 'status' => 'active']);
    $this->targetSupplier = Supplier::factory()->create(['name' => 'Target Supply', 'status' => 'active']);

    $this->bulk = app(ProductBulkActionService::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function bulkProduct(array $attributes = []): Product
{
    return Product::factory()->create([
        'category_id' => test()->category->id,
        'supplier_id' => test()->supplier->id,
        ...$attributes,
    ]);
}

// ─── Service behaviour ────────────────────────────────────────────────────────

test('bulk category update reassigns every selected product', function () {
    $first = bulkProduct();
    $second = bulkProduct();
    $untouched = bulkProduct();

    $updated = $this->bulk->updateCategory([$first->id, $second->id], $this->targetCategory->id);

    expect($updated)->toBe(2)
        ->and($first->fresh()->category_id)->toBe($this->targetCategory->id)
        ->and($second->fresh()->category_id)->toBe($this->targetCategory->id)
        ->and($untouched->fresh()->category_id)->toBe($this->category->id);
});

test('bulk supplier update reassigns every selected product', function () {
    $first = bulkProduct();
    $second = bulkProduct();

    $updated = $this->bulk->updateSupplier([$first->id, $second->id], $this->targetSupplier->id);

    expect($updated)->toBe(2)
        ->and($first->fresh()->supplier_id)->toBe($this->targetSupplier->id)
        ->and($second->fresh()->supplier_id)->toBe($this->targetSupplier->id);
});

test('bulk minimum stock update writes the new threshold', function () {
    $first = bulkProduct(['minimum_stock' => 5]);
    $second = bulkProduct(['minimum_stock' => 8]);

    $updated = $this->bulk->updateMinimumStock([$first->id, $second->id], 25);

    expect($updated)->toBe(2)
        ->and($first->fresh()->minimum_stock)->toBe(25)
        ->and($second->fresh()->minimum_stock)->toBe(25);
});

test('bulk minimum stock update re-evaluates low stock alerts', function () {
    $product = bulkProduct(['current_stock' => 10, 'minimum_stock' => 2]);

    expect(LowStockNotification::where('product_id', $product->id)->whereNull('resolved_at')->exists())->toBeFalse();

    $this->bulk->updateMinimumStock([$product->id], 20);

    expect(LowStockNotification::where('product_id', $product->id)->whereNull('resolved_at')->exists())->toBeTrue();
});

test('bulk status update writes the new status', function () {
    $first = bulkProduct(['status' => 'active']);
    $second = bulkProduct(['status' => 'active']);

    $updated = $this->bulk->updateStatus([$first->id, $second->id], 'discontinued');

    expect($updated)->toBe(2)
        ->and($first->fresh()->status)->toBe('discontinued')
        ->and($second->fresh()->status)->toBe('discontinued');
});

test('bulk updates are recorded in the activity log', function () {
    $product = bulkProduct();

    $this->bulk->updateCategory([$product->id], $this->targetCategory->id);

    expect(
        Activity::where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('description', 'updated')
            ->exists()
    )->toBeTrue();
});

test('bulk archive soft deletes products without transaction history', function () {
    $first = bulkProduct();
    $second = bulkProduct();

    $result = $this->bulk->archive([$first->id, $second->id]);

    expect($result['archived'])->toBe(2)
        ->and($result['blocked'])->toBe([])
        ->and($first->fresh()->trashed())->toBeTrue()
        ->and($second->fresh()->trashed())->toBeTrue()
        ->and(Product::count())->toBe(0)
        ->and(Product::withTrashed()->count())->toBe(2);
});

test('bulk archive keeps products that have transaction history', function () {
    $withHistory = bulkProduct();
    $clean = bulkProduct();

    InventoryTransaction::factory()->for($withHistory)->create(['user_id' => $this->admin->id]);

    $result = $this->bulk->archive([$withHistory->id, $clean->id]);

    expect($result['archived'])->toBe(1)
        ->and($result['blocked'])->toBe([$withHistory->sku])
        ->and($withHistory->fresh()->trashed())->toBeFalse()
        ->and($clean->fresh()->trashed())->toBeTrue();
});

test('bulk actions ignore empty and invalid selections', function () {
    expect($this->bulk->updateCategory([], $this->targetCategory->id))->toBe(0)
        ->and($this->bulk->updateMinimumStock([0, -3], 10))->toBe(0)
        ->and($this->bulk->archive([]))->toBe(['archived' => 0, 'blocked' => []]);
});

test('bulk actions deduplicate repeated ids', function () {
    $product = bulkProduct(['minimum_stock' => 1]);

    expect($this->bulk->updateMinimumStock([$product->id, $product->id], 9))->toBe(1)
        ->and($product->fresh()->minimum_stock)->toBe(9);
});

// ─── Livewire integration ─────────────────────────────────────────────────────

test('selecting all products on the page fills the selection', function () {
    $products = Product::factory()->count(3)->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selectPage', true);

    expect($component->get('selected'))->toHaveCount(3);

    foreach ($products as $product) {
        expect($component->get('selected'))->toContain((string) $product->id);
    }

    $component->set('selectPage', false);

    expect($component->get('selected'))->toBe([]);
});

test('select all on the page only covers the filtered page', function () {
    Product::factory()->count(12)->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selectPage', true);

    // Page size is 10.
    expect($component->get('selected'))->toHaveCount(10);
});

test('changing the page clears the select page toggle', function () {
    Product::factory()->count(12)->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selectPage', true)
        ->assertSet('selectPage', true)
        ->call('nextPage')
        ->assertSet('selectPage', false);
});

test('the bulk toolbar appears only when products are selected', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->assertDontSeeHtml('data-test="bulk-toolbar"')
        ->set('selected', [(string) $product->id])
        ->assertSeeHtml('data-test="bulk-toolbar"')
        ->assertSee('1 product selected');
});

test('admin can bulk update the category from the products page', function () {
    $first = bulkProduct();
    $second = bulkProduct();

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $first->id, (string) $second->id])
        ->call('openBulkModal', 'category')
        ->assertSet('showBulkModal', true)
        ->assertSet('bulkAction', 'category')
        ->set('bulkCategoryId', $this->targetCategory->id)
        ->call('applyBulkAction')
        ->assertHasNoErrors()
        ->assertSet('showBulkModal', false)
        ->assertSet('selected', [])
        ->assertDispatched('products-updated');

    expect($first->fresh()->category_id)->toBe($this->targetCategory->id)
        ->and($second->fresh()->category_id)->toBe($this->targetCategory->id);
});

test('admin can bulk update the supplier from the products page', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'supplier')
        ->set('bulkSupplierId', $this->targetSupplier->id)
        ->call('applyBulkAction')
        ->assertHasNoErrors();

    expect($product->fresh()->supplier_id)->toBe($this->targetSupplier->id);
});

test('admin can bulk update the minimum stock from the products page', function () {
    $product = bulkProduct(['minimum_stock' => 3]);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'minimum_stock')
        ->set('bulkMinimumStock', 42)
        ->call('applyBulkAction')
        ->assertHasNoErrors();

    expect($product->fresh()->minimum_stock)->toBe(42);
});

test('admin can bulk update the status from the products page', function () {
    $product = bulkProduct(['status' => 'active']);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'status')
        ->set('bulkStatus', 'inactive')
        ->call('applyBulkAction')
        ->assertHasNoErrors();

    expect($product->fresh()->status)->toBe('inactive');
});

test('bulk updates validate their input', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'category')
        ->call('applyBulkAction')
        ->assertHasErrors(['bulkCategoryId' => 'required'])
        ->assertSet('showBulkModal', true);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'category')
        ->set('bulkCategoryId', 99999)
        ->call('applyBulkAction')
        ->assertHasErrors(['bulkCategoryId' => 'exists']);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'minimum_stock')
        ->set('bulkMinimumStock', -5)
        ->call('applyBulkAction')
        ->assertHasErrors(['bulkMinimumStock' => 'min']);

    expect($product->fresh()->category_id)->toBe($this->category->id);
});

test('an unknown bulk action is rejected', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'drop_table')
        ->assertNotFound();
});

test('bulk actions require a selection', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('openBulkModal', 'category')
        ->assertSet('showBulkModal', false);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('confirmBulkArchive')
        ->assertSet('showBulkArchiveModal', false);
});

test('bulk archive from the products page needs confirmation and reports blocked products', function () {
    $clean = bulkProduct();
    $withHistory = bulkProduct();
    InventoryTransaction::factory()->for($withHistory)->create(['user_id' => $this->admin->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $clean->id, (string) $withHistory->id])
        ->call('confirmBulkArchive')
        ->assertSet('showBulkArchiveModal', true)
        ->assertSee('Archive 2 products?')
        ->call('bulkArchive')
        ->assertSet('showBulkArchiveModal', false)
        ->assertSet('selected', [])
        ->assertDispatched('products-updated');

    expect($clean->fresh()->trashed())->toBeTrue()
        ->and($withHistory->fresh()->trashed())->toBeFalse();
});

test('staff can run bulk actions', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->staff)
        ->test('pages::products')
        ->assertSet('canBulkManage', true)
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'minimum_stock')
        ->set('bulkMinimumStock', 15)
        ->call('applyBulkAction')
        ->assertHasNoErrors();

    expect($product->fresh()->minimum_stock)->toBe(15);
});

// ─── Permissions ──────────────────────────────────────────────────────────────

test('users without the bulk permission cannot run bulk actions', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo('view products');
    $product = bulkProduct();

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->assertSet('canBulkManage', false)
        ->assertDontSeeHtml('data-test="select-page"')
        ->set('selected', [(string) $product->id])
        ->call('openBulkModal', 'category')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('confirmBulkArchive')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('bulkArchive')
        ->assertForbidden();

    expect($product->fresh()->trashed())->toBeFalse();
});

test('supplier users cannot run bulk actions on their catalog', function () {
    $supplierUser = User::factory()->create([
        'status' => 'active',
        'supplier_id' => $this->supplier->id,
    ]);
    $supplierUser->assignRole('Supplier');
    $supplierUser->givePermissionTo(['bulk manage products', 'import products', 'export catalog']);

    $product = bulkProduct();

    Livewire::actingAs($supplierUser)
        ->test('pages::products')
        ->assertSet('canBulkManage', false)
        ->set('selected', [(string) $product->id])
        ->call('applyBulkAction')
        ->assertForbidden();
});

test('the bulk operations permissions seeder grants admin and staff access only', function () {
    Permission::whereIn('name', ['import products', 'bulk manage products', 'export catalog'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(BulkOperationsPermissionsSeeder::class);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('Admin');

    $staff = User::factory()->create(['status' => 'active']);
    $staff->assignRole('Staff');

    $supplierUser = User::factory()->create(['status' => 'active']);
    $supplierUser->assignRole('Supplier');

    foreach (['import products', 'bulk manage products', 'export catalog'] as $permission) {
        expect($admin->can($permission))->toBeTrue()
            ->and($staff->can($permission))->toBeTrue()
            ->and($supplierUser->can($permission))->toBeFalse();
    }

    // Re-running the seeder must not duplicate anything.
    $this->seed(BulkOperationsPermissionsSeeder::class);

    expect(Permission::where('name', 'import products')->count())->toBe(1);
});

test('authorization flags cannot be tampered with from the browser', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo('view products');

    foreach (['canBulkManage', 'canImport', 'canExportCatalog', 'isSupplier', 'isReadOnly'] as $property) {
        expect(fn () => Livewire::actingAs($viewer)
            ->test('pages::products')
            ->set($property, $property === 'isReadOnly' ? false : true)
        )->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

test('bulk selections are cleared when the filters change', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->set('search', 'anything')
        ->assertSet('selected', [])
        ->set('selected', [(string) $product->id])
        ->set('filterStatus', 'active')
        ->assertSet('selected', []);
});

test('bulk actions never touch more than the maximum selection size', function () {
    expect(ProductBulkActionService::MAX_SELECTION)->toBe(500);

    $products = Product::factory()->count(3)->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    $padded = [...range(100000, 100600), ...$products->pluck('id')->all()];

    // Ids beyond the cap are dropped, so the real products at the end are not updated.
    expect($this->bulk->updateMinimumStock($padded, 77))->toBe(0);
});

test('archiving keeps the product image so it can be restored', function () {
    $product = bulkProduct(['image_path' => 'products/example.jpg']);

    $this->bulk->archive([$product->id]);

    expect($product->fresh()->trashed())->toBeTrue()
        ->and($product->fresh()->image_path)->toBe('products/example.jpg');
});

test('staff can bulk archive products', function () {
    $product = bulkProduct();

    Livewire::actingAs($this->staff)
        ->test('pages::products')
        ->set('selected', [(string) $product->id])
        ->call('confirmBulkArchive')
        ->assertSet('showBulkArchiveModal', true)
        ->assertSee($product->sku)
        ->call('bulkArchive');

    expect($product->fresh()->trashed())->toBeTrue();
});
