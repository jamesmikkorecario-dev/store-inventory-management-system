<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogExporter;
use App\Services\CatalogFilters;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permissions = collect([
        'view products',
        'manage products',
        'view suppliers',
        'manage suppliers',
        'view categories',
        'manage categories',
        'import products',
        'bulk manage products',
        'export catalog',
    ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name]));

    Role::firstOrCreate(['name' => 'Admin'])->givePermissionTo($permissions->all());
    Role::firstOrCreate(['name' => 'Staff'])->givePermissionTo([
        'view products',
        'view suppliers',
        'view categories',
        'import products',
        'bulk manage products',
        'export catalog',
    ]);
    Role::firstOrCreate(['name' => 'Supplier']);

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('Admin');

    $this->staff = User::factory()->create(['status' => 'active']);
    $this->staff->assignRole('Staff');

    $this->category = Category::factory()->create(['name' => 'Included Category']);
    $this->otherCategory = Category::factory()->create(['name' => 'Excluded Category']);
    $this->supplier = Supplier::factory()->create(['name' => 'Included Supply', 'status' => 'active']);
    $this->otherSupplier = Supplier::factory()->create(['name' => 'Excluded Supply', 'status' => 'inactive']);

    $this->exporter = app(CatalogExporter::class);
});

function exportBody(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function catalogProduct(array $attributes = []): Product
{
    return Product::factory()->create([
        'category_id' => test()->category->id,
        'supplier_id' => test()->supplier->id,
        ...$attributes,
    ]);
}

// ─── Product exports ──────────────────────────────────────────────────────────

test('product export contains the catalog columns and totals', function () {
    catalogProduct([
        'sku' => 'SKU-EXP-001',
        'name' => 'Exported Keyboard',
        'cost_price' => 20.00,
        'selling_price' => 35.00,
        'current_stock' => 4,
        'minimum_stock' => 2,
    ]);

    $response = $this->exporter->csv('products', new CatalogFilters);
    $content = exportBody($response);

    expect($content)->toContain('SKU,Identifier,"Product Name"')
        ->and($content)->toContain('SKU-EXP-001')
        ->and($content)->toContain('Exported Keyboard')
        ->and($content)->toContain('Included Category')
        ->and($content)->toContain('Included Supply')
        ->and($content)->toContain('$20.00')
        ->and($content)->toContain('In Stock')
        ->and($content)->toContain('TOTALS (1 products)')
        // 4 * 20.00 cost value and 4 * 35.00 retail value.
        ->and($content)->toContain('$80.00')
        ->and($content)->toContain('$140.00')
        ->and($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('sims_products_export_');
});

test('product export honours the search filter', function () {
    catalogProduct(['sku' => 'SKU-KEEP-1', 'name' => 'Keeper Widget']);
    catalogProduct(['sku' => 'SKU-DROP-1', 'name' => 'Dropped Widget']);

    $content = exportBody($this->exporter->csv('products', new CatalogFilters(search: 'Keeper')));

    expect($content)->toContain('SKU-KEEP-1')
        ->and($content)->not->toContain('SKU-DROP-1')
        ->and($content)->toContain('TOTALS (1 products)');
});

test('product export honours the category and supplier filters', function () {
    catalogProduct(['sku' => 'SKU-CAT-IN']);
    catalogProduct(['sku' => 'SKU-CAT-OUT', 'category_id' => $this->otherCategory->id]);
    catalogProduct(['sku' => 'SKU-SUP-OUT', 'supplier_id' => $this->otherSupplier->id]);

    $byCategory = exportBody($this->exporter->csv('products', new CatalogFilters(categoryId: $this->category->id)));
    $bySupplier = exportBody($this->exporter->csv('products', new CatalogFilters(supplierId: $this->supplier->id)));

    expect($byCategory)->toContain('SKU-CAT-IN')
        ->and($byCategory)->toContain('SKU-SUP-OUT')
        ->and($byCategory)->not->toContain('SKU-CAT-OUT')
        ->and($bySupplier)->toContain('SKU-CAT-IN')
        ->and($bySupplier)->toContain('SKU-CAT-OUT')
        ->and($bySupplier)->not->toContain('SKU-SUP-OUT');
});

test('product export honours the stock status filter', function () {
    catalogProduct(['sku' => 'SKU-OUT-1', 'current_stock' => 0, 'minimum_stock' => 5]);
    catalogProduct(['sku' => 'SKU-LOW-1', 'current_stock' => 3, 'minimum_stock' => 5]);
    catalogProduct(['sku' => 'SKU-OK-1', 'current_stock' => 50, 'minimum_stock' => 5]);

    $out = exportBody($this->exporter->csv('products', new CatalogFilters(stockStatus: 'out')));
    $low = exportBody($this->exporter->csv('products', new CatalogFilters(stockStatus: 'low')));

    expect($out)->toContain('SKU-OUT-1')
        ->and($out)->toContain('Out of Stock')
        ->and($out)->not->toContain('SKU-OK-1')
        ->and($low)->toContain('SKU-LOW-1')
        ->and($low)->toContain('SKU-OUT-1')
        ->and($low)->not->toContain('SKU-OK-1');
});

test('product export honours the status filter', function () {
    catalogProduct(['sku' => 'SKU-ACTIVE-1', 'status' => 'active']);
    catalogProduct(['sku' => 'SKU-DISC-1', 'status' => 'discontinued']);

    $content = exportBody($this->exporter->csv('products', new CatalogFilters(status: 'discontinued')));

    expect($content)->toContain('SKU-DISC-1')
        ->and($content)->not->toContain('SKU-ACTIVE-1');
});

test('product export excludes archived products', function () {
    $archived = catalogProduct(['sku' => 'SKU-ARCH-1']);
    $archived->delete();
    catalogProduct(['sku' => 'SKU-LIVE-1']);

    $content = exportBody($this->exporter->csv('products', new CatalogFilters));

    expect($content)->toContain('SKU-LIVE-1')
        ->and($content)->not->toContain('SKU-ARCH-1')
        ->and($content)->toContain('TOTALS (1 products)');
});

test('an unknown export entity falls back to products', function () {
    catalogProduct(['sku' => 'SKU-FALLBACK-1']);

    $response = $this->exporter->csv('nonsense', new CatalogFilters);

    expect(exportBody($response))->toContain('SKU-FALLBACK-1')
        ->and($response->headers->get('content-disposition'))->toContain('sims_products_export_');
});

// ─── Supplier and category exports ────────────────────────────────────────────

test('supplier export contains contact details, counts and totals', function () {
    catalogProduct();
    catalogProduct();

    $response = $this->exporter->csv('suppliers', new CatalogFilters);
    $content = exportBody($response);

    expect($content)->toContain('Supplier Name')
        ->and($content)->toContain('Included Supply')
        ->and($content)->toContain('Excluded Supply')
        ->and($content)->toContain('TOTALS (2 suppliers)')
        ->and($content)->toContain('Active: 1')
        ->and($response->headers->get('content-disposition'))->toContain('sims_suppliers_export_');
});

test('supplier export honours the search and status filters', function () {
    $bySearch = exportBody($this->exporter->csv('suppliers', new CatalogFilters(search: 'Included')));
    $byStatus = exportBody($this->exporter->csv('suppliers', new CatalogFilters(status: 'inactive')));

    expect($bySearch)->toContain('Included Supply')
        ->and($bySearch)->not->toContain('Excluded Supply')
        ->and($byStatus)->toContain('Excluded Supply')
        ->and($byStatus)->not->toContain('Included Supply');
});

test('category export contains product counts and totals', function () {
    catalogProduct();

    $response = $this->exporter->csv('categories', new CatalogFilters);
    $content = exportBody($response);

    expect($content)->toContain('Category Name')
        ->and($content)->toContain('Included Category')
        ->and($content)->toContain('TOTALS (2 categories)')
        ->and($response->headers->get('content-disposition'))->toContain('sims_categories_export_');
});

test('category export honours the search filter', function () {
    $content = exportBody($this->exporter->csv('categories', new CatalogFilters(search: 'Included')));

    expect($content)->toContain('Included Category')
        ->and($content)->not->toContain('Excluded Category')
        ->and($content)->toContain('TOTALS (1 categories)');
});

// ─── Livewire integration ─────────────────────────────────────────────────────

test('the products page exports the currently filtered catalog', function () {
    catalogProduct(['sku' => 'SKU-UI-KEEP']);
    catalogProduct(['sku' => 'SKU-UI-DROP', 'category_id' => $this->otherCategory->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('filterCategory', (string) $this->category->id)
        ->call('exportCsv')
        ->assertFileDownloaded();

    $content = exportBody($this->exporter->csv('products', new CatalogFilters(categoryId: $this->category->id)));

    expect($content)->toContain('SKU-UI-KEEP')
        ->and($content)->not->toContain('SKU-UI-DROP');
});

test('the suppliers and categories pages export their filtered lists', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::suppliers')
        ->set('search', 'Included')
        ->call('exportCsv')
        ->assertFileDownloaded();

    Livewire::actingAs($this->admin)
        ->test('pages::categories')
        ->set('search', 'Included')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

test('staff can export the catalog from every page', function () {
    Livewire::actingAs($this->staff)->test('pages::products')->call('exportCsv')->assertOk();
    Livewire::actingAs($this->staff)->test('pages::suppliers')->call('exportCsv')->assertOk();
    Livewire::actingAs($this->staff)->test('pages::categories')->call('exportCsv')->assertOk();
});

test('the export button is only rendered for users with the permission', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo(['view products', 'view suppliers', 'view categories']);

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->assertSet('canExportCatalog', false)
        ->assertDontSeeHtml('wire:click="exportCsv"');

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->assertSeeHtml('wire:click="exportCsv"');

    Livewire::actingAs($this->admin)
        ->test('pages::suppliers')
        ->assertSeeHtml('wire:click="exportCsv"');

    Livewire::actingAs($this->admin)
        ->test('pages::categories')
        ->assertSeeHtml('wire:click="exportCsv"');
});

// ─── Permissions ──────────────────────────────────────────────────────────────

test('users without the export permission cannot export any catalog', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo(['view products', 'view suppliers', 'view categories']);

    Livewire::actingAs($viewer)->test('pages::products')->call('exportCsv')->assertForbidden();
    Livewire::actingAs($viewer)->test('pages::suppliers')->call('exportCsv')->assertForbidden();
    Livewire::actingAs($viewer)->test('pages::categories')->call('exportCsv')->assertForbidden();
});

test('supplier users cannot export the catalog', function () {
    $supplierUser = User::factory()->create([
        'status' => 'active',
        'supplier_id' => $this->supplier->id,
    ]);
    $supplierUser->assignRole('Supplier');
    $supplierUser->givePermissionTo('export catalog');

    Livewire::actingAs($supplierUser)
        ->test('pages::products')
        ->assertSet('canExportCatalog', false)
        ->call('exportCsv')
        ->assertForbidden();
});

test('supplier users only ever see their own products in the catalog listing', function () {
    $supplierUser = User::factory()->create([
        'status' => 'active',
        'supplier_id' => $this->supplier->id,
    ]);
    $supplierUser->assignRole('Supplier');

    $own = catalogProduct(['sku' => 'SKU-OWN-1']);
    $foreign = catalogProduct(['sku' => 'SKU-FOREIGN-1', 'supplier_id' => $this->otherSupplier->id]);

    Livewire::actingAs($supplierUser)
        ->test('pages::products')
        ->assertSee($own->sku)
        ->assertDontSee($foreign->sku);
});

test('a supplier user without a linked supplier sees no products', function () {
    $orphan = User::factory()->create([
        'status' => 'active',
        'supplier_id' => null,
    ]);
    $orphan->assignRole('Supplier');

    $product = catalogProduct(['sku' => 'SKU-HIDDEN-1']);

    Livewire::actingAs($orphan)
        ->test('pages::products')
        ->assertDontSee($product->sku)
        ->assertSee('No Products Yet');
});

test('every catalog export starts with a UTF-8 BOM for Excel', function () {
    catalogProduct();

    foreach (['products', 'suppliers', 'categories'] as $entity) {
        expect(exportBody($this->exporter->csv($entity, new CatalogFilters)))
            ->toStartWith(chr(0xEF).chr(0xBB).chr(0xBF));
    }
});

test('exports stream every row beyond the internal chunk size', function () {
    Product::factory()->count(30)->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 1,
        'cost_price' => 1.00,
        'selling_price' => 2.00,
    ]);

    $content = exportBody($this->exporter->csv('products', new CatalogFilters));
    $dataRows = collect(explode("\n", trim($content)))->filter(fn (string $line): bool => str_starts_with($line, 'SKU-'));

    expect($dataRows)->toHaveCount(30)
        ->and($content)->toContain('TOTALS (30 products)');
});
