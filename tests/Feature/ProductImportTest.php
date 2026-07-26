<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Http\UploadedFile;
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

    $this->category = Category::factory()->create(['name' => 'Computer Accessories']);
    $this->supplier = Supplier::factory()->create(['name' => 'Apex Electronics', 'status' => 'active']);

    $this->importer = app(ProductImportService::class);
});

/**
 * Write a CSV file to a temporary path and return that path.
 */
function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'sims_import_').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Valid CSV header used by most tests.
 */
function importHeader(): string
{
    return "sku,name,category,supplier,cost_price,selling_price,minimum_stock,description,status\n";
}

/**
 * Read a streamed response body.
 */
function streamedContent(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

// ─── Structure validation ─────────────────────────────────────────────────────

test('import rejects a file with missing required columns', function () {
    $path = csvFile("sku,name,category\nSKU-1,Widget,Computer Accessories\n");

    $result = $this->importer->import($path);

    expect($result->failed())->toBeTrue()
        ->and($result->imported)->toBe(0)
        ->and($result->fatalErrors[0])->toContain('missing the required column(s)')
        ->and($result->fatalErrors[0])->toContain('supplier')
        ->and($result->fatalErrors[0])->toContain('cost_price')
        ->and(Product::count())->toBe(0);

    unlink($path);
});

test('import rejects an empty file', function () {
    $path = csvFile('');

    $result = $this->importer->import($path);

    expect($result->failed())->toBeTrue()
        ->and($result->fatalErrors[0])->toContain('empty');

    unlink($path);
});

test('import rejects a file without data rows', function () {
    $path = csvFile(importHeader());

    $result = $this->importer->import($path);

    expect($result->failed())->toBeTrue()
        ->and($result->fatalErrors[0])->toContain('does not contain any data rows');

    unlink($path);
});

test('import rejects an unreadable path without touching the catalog', function () {
    $result = $this->importer->import('/tmp/definitely-missing-sims-import.csv');

    expect($result->failed())->toBeTrue()
        ->and(Product::count())->toBe(0);
});

test('import accepts headers with different casing, spacing and a BOM', function () {
    $path = csvFile(
        chr(0xEF).chr(0xBB).chr(0xBF)."SKU, Name ,Category,Supplier,Cost Price,Selling Price,Minimum Stock\n".
        "SKU-BOM-1,BOM Widget,Computer Accessories,Apex Electronics,10.00,20.00,5\n"
    );

    $result = $this->importer->import($path);

    expect($result->failed())->toBeFalse()
        ->and($result->imported)->toBe(1)
        ->and(Product::where('sku', 'SKU-BOM-1')->exists())->toBeTrue();

    unlink($path);
});

// ─── Row creation ─────────────────────────────────────────────────────────────

test('import creates products from valid rows', function () {
    $path = csvFile(
        importHeader().
        "SKU-IMP-001,Imported Keyboard,Computer Accessories,Apex Electronics,45.00,79.99,10,A keyboard,active\n".
        "SKU-IMP-002,Imported Mouse,Computer Accessories,Apex Electronics,15.50,29.99,4,,inactive\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(2)
        ->and($result->totalRows)->toBe(2)
        ->and($result->skipped())->toBe(0)
        ->and($result->hasRowErrors())->toBeFalse();

    $keyboard = Product::where('sku', 'SKU-IMP-001')->firstOrFail();

    expect($keyboard->name)->toBe('Imported Keyboard')
        ->and($keyboard->category_id)->toBe($this->category->id)
        ->and($keyboard->supplier_id)->toBe($this->supplier->id)
        ->and((float) $keyboard->cost_price)->toBe(45.00)
        ->and((float) $keyboard->selling_price)->toBe(79.99)
        ->and($keyboard->minimum_stock)->toBe(10)
        ->and($keyboard->description)->toBe('A keyboard')
        ->and($keyboard->status)->toBe('active')
        // Stock is only ever moved by inventory transactions.
        ->and($keyboard->current_stock)->toBe(0)
        ->and($keyboard->identifier)->not->toBeEmpty();

    $mouse = Product::where('sku', 'SKU-IMP-002')->firstOrFail();

    expect($mouse->status)->toBe('inactive')
        ->and($mouse->description)->toBeNull();

    unlink($path);
});

test('import defaults the status to active and skips blank lines', function () {
    $path = csvFile(
        importHeader().
        "SKU-DEF-001,Defaulted Product,Computer Accessories,Apex Electronics,5,10,1,,\n".
        "\n".
        "  \n"
    );

    $result = $this->importer->import($path);

    expect($result->totalRows)->toBe(1)
        ->and($result->imported)->toBe(1)
        ->and(Product::where('sku', 'SKU-DEF-001')->value('status'))->toBe('active');

    unlink($path);
});

test('import matches category and supplier names case insensitively', function () {
    $path = csvFile(
        importHeader().
        "SKU-CASE-1,Case Widget,computer ACCESSORIES,apex electronics,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(1)
        ->and(Product::where('sku', 'SKU-CASE-1')->value('category_id'))->toBe($this->category->id);

    unlink($path);
});

// ─── Duplicate detection ──────────────────────────────────────────────────────

test('import detects SKUs that already exist in the catalog', function () {
    Product::factory()->create([
        'sku' => 'SKU-EXIST-1',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);

    $path = csvFile(
        importHeader().
        "SKU-EXIST-1,Duplicate Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n".
        "SKU-NEW-1,New Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(1)
        ->and($result->skipped())->toBe(1)
        ->and($result->rowErrors[0]['row'])->toBe(2)
        ->and($result->rowErrors[0]['sku'])->toBe('SKU-EXIST-1')
        ->and($result->rowErrors[0]['messages'][0])->toContain('already exists in the catalog')
        ->and(Product::where('sku', 'SKU-EXIST-1')->count())->toBe(1)
        ->and(Product::where('sku', 'SKU-NEW-1')->exists())->toBeTrue();

    unlink($path);
});

test('import detects SKUs that already exist as soft deleted products', function () {
    $archived = Product::factory()->create([
        'sku' => 'SKU-ARCHIVED-1',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
    ]);
    $archived->delete();

    $path = csvFile(
        importHeader().
        "SKU-ARCHIVED-1,Archived Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(0)
        ->and($result->rowErrors[0]['messages'][0])->toContain('already exists');

    unlink($path);
});

test('import detects duplicate SKUs inside the same file', function () {
    $path = csvFile(
        importHeader().
        "SKU-DUP-1,First Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n".
        "SKU-DUP-1,Second Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(1)
        ->and($result->skipped())->toBe(1)
        ->and($result->rowErrors[0]['row'])->toBe(3)
        ->and($result->rowErrors[0]['messages'][0])->toContain('duplicated in this file')
        ->and(Product::where('sku', 'SKU-DUP-1')->count())->toBe(1)
        ->and(Product::where('name', 'First Widget')->exists())->toBeTrue();

    unlink($path);
});

// ─── Row level validation ─────────────────────────────────────────────────────

test('import reports friendly row level validation errors', function () {
    $path = csvFile(
        importHeader().
        ",No SKU,Computer Accessories,Apex Electronics,1,2,3,,active\n".
        "SKU-BAD-2,,Computer Accessories,Apex Electronics,1,2,3,,active\n".
        "SKU-BAD-3,Bad Price,Computer Accessories,Apex Electronics,abc,2,3,,active\n".
        "SKU-BAD-4,Negative Price,Computer Accessories,Apex Electronics,-5,2,3,,active\n".
        "SKU-BAD-5,Bad Minimum,Computer Accessories,Apex Electronics,1,2,1.5,,active\n".
        "SKU-BAD-6,Bad Status,Computer Accessories,Apex Electronics,1,2,3,,archived\n".
        "SKU-BAD-7,Unknown Category,Nope Category,Apex Electronics,1,2,3,,active\n".
        "SKU-BAD-8,Unknown Supplier,Computer Accessories,Nope Supplier,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    $messages = collect($result->rowErrors)->mapWithKeys(
        fn (array $error): array => [$error['row'] => implode(' ', $error['messages'])]
    );

    expect($result->imported)->toBe(0)
        ->and($result->skipped())->toBe(8)
        ->and($messages[2])->toContain('SKU is required.')
        ->and($messages[3])->toContain('Product name is required.')
        ->and($messages[4])->toContain('Cost price must be a number')
        ->and($messages[5])->toContain('Cost price cannot be negative.')
        ->and($messages[6])->toContain('Minimum stock must be a whole number.')
        ->and($messages[7])->toContain('Status must be one of: active, inactive, discontinued.')
        ->and($messages[8])->toContain('Category "Nope Category" does not exist')
        ->and($messages[9])->toContain('Supplier "Nope Supplier" does not exist')
        ->and(Product::count())->toBe(0);

    unlink($path);
});

test('import rejects rows referencing an inactive supplier with a clear reason', function () {
    Supplier::factory()->create(['name' => 'Dormant Supply', 'status' => 'inactive']);

    $path = csvFile(
        importHeader().
        "SKU-INACT-1,Widget,Computer Accessories,Dormant Supply,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(0)
        ->and($result->rowErrors[0]['messages'][0])->toContain('is not active');

    unlink($path);
});

test('import reports rows whose column count does not match the header', function () {
    $path = csvFile(
        importHeader().
        "SKU-SHORT-1,Short Row,Computer Accessories\n"
    );

    $result = $this->importer->import($path);

    expect($result->imported)->toBe(0)
        ->and(implode(' ', $result->rowErrors[0]['messages']))->toContain('column(s) but the header defines');

    unlink($path);
});

test('import rejects files above the row limit without creating anything', function () {
    $rows = '';

    for ($i = 1; $i <= ProductImportService::MAX_ROWS + 1; $i++) {
        $rows .= "SKU-BULK-{$i},Bulk Widget {$i},Computer Accessories,Apex Electronics,1,2,3,,active\n";
    }

    $path = csvFile(importHeader().$rows);

    $result = $this->importer->import($path);

    expect($result->failed())->toBeTrue()
        ->and($result->fatalErrors[0])->toContain('more than 2,000 data rows')
        ->and(Product::count())->toBe(0);

    unlink($path);
});

// ─── Reports & template ───────────────────────────────────────────────────────

test('import rejects rows whose prices exceed the column limits', function () {
    $path = csvFile(
        importHeader().
        "SKU-HUGE-1,Huge Price,Computer Accessories,Apex Electronics,1e30,2,3,,active\n".
        "SKU-HUGE-2,Huge Minimum,Computer Accessories,Apex Electronics,1,2,99999999,,active\n".
        "SKU-OK-1,Fine Product,Computer Accessories,Apex Electronics,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);

    $messages = collect($result->rowErrors)->mapWithKeys(
        fn (array $error): array => [$error['row'] => implode(' ', $error['messages'])]
    );

    expect($result->imported)->toBe(1)
        ->and($result->skipped())->toBe(2)
        ->and($messages[2])->toContain('Cost price may not exceed')
        ->and($messages[3])->toContain('Minimum stock may not exceed')
        ->and(Product::where('sku', 'SKU-OK-1')->exists())->toBeTrue();

    unlink($path);
});

test('import template contains every supported column', function () {
    $content = streamedContent($this->importer->template());

    expect($content)->toContain(implode(',', ProductImportService::REQUIRED_COLUMNS))
        ->and($content)->toContain('description')
        ->and($content)->toContain('status')
        ->and($content)->toContain('SKU-EXAMPLE-001');
});

test('validation report lists every rejected row', function () {
    $path = csvFile(
        importHeader().
        "SKU-RPT-1,,Computer Accessories,Apex Electronics,1,2,3,,active\n"
    );

    $result = $this->importer->import($path);
    $content = streamedContent($this->importer->errorReport($result->rowErrors));

    expect($content)->toContain('CSV Row')
        ->and($content)->toContain('Validation Errors')
        ->and($content)->toContain('SKU-RPT-1')
        ->and($content)->toContain('Product name is required.');

    unlink($path);
});

// ─── Livewire integration ─────────────────────────────────────────────────────

test('admin can import products through the products page', function () {
    $csv = importHeader().
        "SKU-UI-001,UI Keyboard,Computer Accessories,Apex Electronics,45.00,79.99,10,,active\n".
        "SKU-UI-002,UI Mouse,Computer Accessories,Apex Electronics,15.00,29.00,5,,active\n";

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('openImportModal')
        ->assertSet('showImportModal', true)
        ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('importProducts')
        ->assertHasNoErrors()
        ->assertSet('importSummary.imported', 2)
        ->assertSet('importSummary.skipped', 0)
        ->assertDispatched('products-updated');

    expect(Product::whereIn('sku', ['SKU-UI-001', 'SKU-UI-002'])->count())->toBe(2);
});

test('staff can import products', function () {
    $csv = importHeader()."SKU-STAFF-1,Staff Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n";

    Livewire::actingAs($this->staff)
        ->test('pages::products')
        ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('importProducts')
        ->assertHasNoErrors()
        ->assertSet('importSummary.imported', 1);

    expect(Product::where('sku', 'SKU-STAFF-1')->exists())->toBeTrue();
});

test('the import surfaces row errors and a downloadable report in the UI', function () {
    $csv = importHeader().
        "SKU-MIX-1,Good Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n".
        "SKU-MIX-2,,Computer Accessories,Apex Electronics,1,2,3,,active\n";

    $component = Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('openImportModal')
        ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('importProducts')
        ->assertSet('importSummary.imported', 1)
        ->assertSet('importSummary.skipped', 1)
        ->assertSee('Row errors');

    $component->call('downloadImportErrors')->assertFileDownloaded();

    expect(Product::where('sku', 'SKU-MIX-1')->exists())->toBeTrue()
        ->and(Product::where('sku', 'SKU-MIX-2')->exists())->toBeFalse();
});

test('the error report download is unavailable when the import was clean', function () {
    $csv = importHeader()."SKU-CLEAN-1,Clean Widget,Computer Accessories,Apex Electronics,1,2,3,,active\n";

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('importProducts')
        ->call('downloadImportErrors')
        ->assertNotFound();
});

test('the import template can be downloaded from the products page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('downloadImportTemplate')
        ->assertFileDownloaded('sims_product_import_template.csv');
});

test('the import requires a csv file', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('importProducts')
        ->assertHasErrors(['importFile' => 'required']);

    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->set('importFile', UploadedFile::fake()->create('products.pdf', 10, 'application/pdf'))
        ->call('importProducts')
        ->assertHasErrors(['importFile' => 'mimes']);
});

test('a fatal import failure reports back without creating products', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::products')
        ->call('openImportModal')
        ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', "sku,name\nSKU-1,Widget\n"))
        ->call('importProducts')
        ->assertSet('importSummary.imported', 0)
        ->assertSee('Import failed');

    expect(Product::count())->toBe(0);
});

// ─── Permissions ──────────────────────────────────────────────────────────────

test('users without the import permission cannot import', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo('view products');

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->call('openImportModal')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->set('importFile', UploadedFile::fake()->createWithContent('products.csv', importHeader()))
        ->call('importProducts')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::products')
        ->call('downloadImportTemplate')
        ->assertForbidden();
});

test('supplier users cannot see or use the import tooling', function () {
    $supplierUser = User::factory()->create([
        'status' => 'active',
        'supplier_id' => $this->supplier->id,
    ]);
    $supplierUser->assignRole('Supplier');
    $supplierUser->givePermissionTo(['import products', 'export catalog', 'bulk manage products']);

    Livewire::actingAs($supplierUser)
        ->test('pages::products')
        ->assertSet('canImport', false)
        ->assertDontSeeHtml('wire:click="openImportModal"')
        ->call('openImportModal')
        ->assertForbidden();
});
