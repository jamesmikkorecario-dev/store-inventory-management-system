<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReportExporter;
use App\Services\ReportFilters;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $viewReports = Permission::firstOrCreate(['name' => 'view reports']);
    $exportReports = Permission::firstOrCreate(['name' => 'export reports']);

    $adminRole = Role::firstOrCreate(['name' => 'Admin']);
    $adminRole->givePermissionTo([$viewReports, $exportReports]);

    $staffRole = Role::firstOrCreate(['name' => 'Staff']);
    $staffRole->givePermissionTo([$viewReports, $exportReports]);

    Role::firstOrCreate(['name' => 'Supplier']);

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('Admin');

    $this->staff = User::factory()->create(['status' => 'active']);
    $this->staff->assignRole('Staff');

    $this->supplierUser = User::factory()->create(['status' => 'active']);
    $this->supplierUser->assignRole('Supplier');

    $this->category = Category::factory()->create(['name' => 'Electronics']);
    $this->otherCategory = Category::factory()->create(['name' => 'Stationery']);
    $this->supplier = Supplier::factory()->create(['name' => 'Apex Supply', 'status' => 'active']);
    $this->otherSupplier = Supplier::factory()->create(['name' => 'Globex Supply', 'status' => 'active']);

    $this->reports = app(ReportService::class);
    $this->exporter = app(ReportExporter::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeProduct(array $attributes = []): Product
{
    return Product::factory()->create($attributes);
}

// ─── Inventory Valuation ──────────────────────────────────────────────────────

test('inventory valuation totals stock multiplied by cost and selling price', function () {
    makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 10,
        'cost_price' => 25.00,
        'selling_price' => 40.00,
    ]);

    makeProduct([
        'category_id' => $this->otherCategory->id,
        'supplier_id' => $this->otherSupplier->id,
        'current_stock' => 4,
        'cost_price' => 50.00,
        'selling_price' => 75.00,
    ]);

    $summary = $this->reports->valuationSummary(new ReportFilters);

    expect($summary['product_count'])->toBe(2)
        ->and($summary['total_units'])->toBe(14)
        ->and($summary['total_cost_value'])->toBe(450.00)   // 10*25 + 4*50
        ->and($summary['total_retail_value'])->toBe(700.00) // 10*40 + 4*75
        ->and($summary['potential_profit'])->toBe(250.00);
});

test('inventory valuation groups value by category and supplier', function () {
    makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 10,
        'cost_price' => 10.00,
    ]);

    makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->otherSupplier->id,
        'current_stock' => 5,
        'cost_price' => 10.00,
    ]);

    makeProduct([
        'category_id' => $this->otherCategory->id,
        'supplier_id' => $this->otherSupplier->id,
        'current_stock' => 1,
        'cost_price' => 10.00,
    ]);

    $byCategory = $this->reports->valuationByCategory(new ReportFilters)->keyBy('label');
    $bySupplier = $this->reports->valuationBySupplier(new ReportFilters)->keyBy('label');

    expect($byCategory['Electronics']['total_value'])->toBe(150.00)
        ->and($byCategory['Electronics']['product_count'])->toBe(2)
        ->and($byCategory['Stationery']['total_value'])->toBe(10.00)
        ->and($bySupplier['Apex Supply']['total_value'])->toBe(100.00)
        ->and($bySupplier['Globex Supply']['total_value'])->toBe(60.00);
});

test('top value products are ranked by stock value and limited', function () {
    $expensive = makeProduct(['current_stock' => 10, 'cost_price' => 100.00]);
    makeProduct(['current_stock' => 1, 'cost_price' => 10.00]);
    makeProduct(['current_stock' => 5, 'cost_price' => 20.00]);

    $top = $this->reports->topValueProducts(new ReportFilters, 2);

    expect($top)->toHaveCount(2)
        ->and($top->first()->id)->toBe($expensive->id);
});

test('valuation excludes soft deleted products', function () {
    $product = makeProduct(['current_stock' => 10, 'cost_price' => 10.00]);
    makeProduct(['current_stock' => 5, 'cost_price' => 10.00]);

    $product->delete();

    expect($this->reports->valuationSummary(new ReportFilters)['total_cost_value'])->toBe(50.00);
});

// ─── Low Stock ────────────────────────────────────────────────────────────────

test('low stock report only includes products at or below minimum stock', function () {
    $low = makeProduct(['current_stock' => 8, 'minimum_stock' => 10]);
    $atMinimum = makeProduct(['current_stock' => 10, 'minimum_stock' => 10]);
    makeProduct(['current_stock' => 40, 'minimum_stock' => 10]);

    $ids = $this->reports->lowStockQuery(new ReportFilters)->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids->contains($low->id))->toBeTrue()
        ->and($ids->contains($atMinimum->id))->toBeTrue();
});

test('low stock severity separates critical from low products', function () {
    $outOfStock = makeProduct(['current_stock' => 0, 'minimum_stock' => 10]);
    $critical = makeProduct(['current_stock' => 4, 'minimum_stock' => 10]);
    $low = makeProduct(['current_stock' => 8, 'minimum_stock' => 10]);

    $summary = $this->reports->lowStockSummary(new ReportFilters);

    expect($summary['total'])->toBe(3)
        ->and($summary['critical'])->toBe(2)
        ->and($summary['low'])->toBe(1)
        ->and($summary['out_of_stock'])->toBe(1)
        ->and($summary['units_required'])->toBe(18) // 10 + 6 + 2
        ->and($this->reports->severityFor($outOfStock))->toBe('critical')
        ->and($this->reports->severityFor($critical))->toBe('critical')
        ->and($this->reports->severityFor($low))->toBe('low');
});

test('low stock report can be filtered by severity, category and supplier', function () {
    $criticalInCategory = makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 0,
        'minimum_stock' => 10,
    ]);

    makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 9,
        'minimum_stock' => 10,
    ]);

    makeProduct([
        'category_id' => $this->otherCategory->id,
        'supplier_id' => $this->otherSupplier->id,
        'current_stock' => 0,
        'minimum_stock' => 10,
    ]);

    $criticalOnly = $this->reports->lowStockQuery(new ReportFilters(severity: 'critical'))->pluck('id');
    $lowOnly = $this->reports->lowStockQuery(new ReportFilters(severity: 'low'))->pluck('id');
    $scoped = $this->reports->lowStockQuery(new ReportFilters(
        severity: 'critical',
        categoryId: $this->category->id,
        supplierId: $this->supplier->id,
    ))->pluck('id');

    expect($criticalOnly)->toHaveCount(2)
        ->and($lowOnly)->toHaveCount(1)
        ->and($scoped->all())->toBe([$criticalInCategory->id]);
});

// ─── Dead Stock ───────────────────────────────────────────────────────────────

test('dead stock identifies products without movement in the selected window', function () {
    $movedYesterday = makeProduct(['current_stock' => 5]);
    InventoryTransaction::factory()->for($movedYesterday)->create([
        'user_id' => $this->admin->id,
        'transaction_date' => now()->subDay(),
    ]);

    $movedFortyDaysAgo = makeProduct(['current_stock' => 5]);
    InventoryTransaction::factory()->for($movedFortyDaysAgo)->create([
        'user_id' => $this->admin->id,
        'transaction_date' => now()->subDays(40),
    ]);

    $movedSeventyDaysAgo = makeProduct(['current_stock' => 5]);
    InventoryTransaction::factory()->for($movedSeventyDaysAgo)->create([
        'user_id' => $this->admin->id,
        'transaction_date' => now()->subDays(70),
    ]);

    $neverMoved = makeProduct(['current_stock' => 5]);

    $thirtyDays = $this->reports->deadStockQuery(new ReportFilters(inactivityDays: 30))->pluck('id');
    $sixtyDays = $this->reports->deadStockQuery(new ReportFilters(inactivityDays: 60))->pluck('id');
    $ninetyDays = $this->reports->deadStockQuery(new ReportFilters(inactivityDays: 90))->pluck('id');

    expect($thirtyDays->all())->toEqualCanonicalizing([$movedFortyDaysAgo->id, $movedSeventyDaysAgo->id, $neverMoved->id])
        ->and($sixtyDays->all())->toEqualCanonicalizing([$movedSeventyDaysAgo->id, $neverMoved->id])
        ->and($ninetyDays->all())->toEqualCanonicalizing([$neverMoved->id])
        ->and($thirtyDays->contains($movedYesterday->id))->toBeFalse();
});

test('dead stock summary reports tied up capital and days since movement', function () {
    $idle = makeProduct(['current_stock' => 4, 'cost_price' => 25.00]);
    InventoryTransaction::factory()->for($idle)->create([
        'user_id' => $this->admin->id,
        'transaction_date' => now()->subDays(45),
    ]);

    $neverMoved = makeProduct(['current_stock' => 2, 'cost_price' => 10.00]);

    $summary = $this->reports->deadStockSummary(new ReportFilters(inactivityDays: 30));

    expect($summary['total'])->toBe(2)
        ->and($summary['total_units'])->toBe(6)
        ->and($summary['tied_value'])->toBe(120.00)
        ->and($summary['never_moved'])->toBe(1);

    $rows = $this->reports->deadStockQuery(new ReportFilters(inactivityDays: 30))->get()->keyBy('id');

    expect($this->reports->daysSinceLastMovement($rows[$idle->id]))->toBe(45)
        ->and($this->reports->daysSinceLastMovement($rows[$neverMoved->id]))->toBeNull();
});

// ─── Transaction Summary ──────────────────────────────────────────────────────

test('transaction summary computes stock in, stock out, adjustments and net movement', function () {
    $product = makeProduct(['category_id' => $this->category->id, 'supplier_id' => $this->supplier->id]);

    InventoryTransaction::factory()->for($product)->stockIn()->create([
        'user_id' => $this->admin->id,
        'quantity' => 100,
        'transaction_date' => now()->subDays(2),
    ]);

    InventoryTransaction::factory()->for($product)->stockOut()->create([
        'user_id' => $this->admin->id,
        'quantity' => 30,
        'transaction_date' => now()->subDay(),
    ]);

    InventoryTransaction::factory()->for($product)->adjustment()->create([
        'user_id' => $this->admin->id,
        'quantity' => -5,
        'transaction_date' => now(),
    ]);

    $summary = $this->reports->transactionSummary(new ReportFilters);

    expect($summary['transaction_count'])->toBe(3)
        ->and($summary['stock_in'])->toBe(100)
        ->and($summary['stock_out'])->toBe(30)
        ->and($summary['adjustments'])->toBe(-5)
        ->and($summary['net_movement'])->toBe(65);
});

test('transaction summary respects date range, type, category and supplier filters', function () {
    $inScope = makeProduct(['category_id' => $this->category->id, 'supplier_id' => $this->supplier->id]);
    $outOfScope = makeProduct(['category_id' => $this->otherCategory->id, 'supplier_id' => $this->otherSupplier->id]);

    InventoryTransaction::factory()->for($inScope)->stockIn()->create([
        'user_id' => $this->admin->id,
        'quantity' => 10,
        'transaction_date' => now()->subDays(3),
    ]);

    InventoryTransaction::factory()->for($inScope)->stockIn()->create([
        'user_id' => $this->admin->id,
        'quantity' => 7,
        'transaction_date' => now()->subDays(40),
    ]);

    InventoryTransaction::factory()->for($inScope)->stockOut()->create([
        'user_id' => $this->admin->id,
        'quantity' => 4,
        'transaction_date' => now()->subDays(3),
    ]);

    InventoryTransaction::factory()->for($outOfScope)->stockIn()->create([
        'user_id' => $this->admin->id,
        'quantity' => 999,
        'transaction_date' => now()->subDays(3),
    ]);

    $dateFiltered = $this->reports->transactionSummary(new ReportFilters(
        startDate: now()->subDays(7)->toDateString(),
        endDate: now()->toDateString(),
    ));

    $typeFiltered = $this->reports->transactionSummary(new ReportFilters(transactionType: 'stock_out'));

    $scoped = $this->reports->transactionSummary(new ReportFilters(
        categoryId: $this->category->id,
        supplierId: $this->supplier->id,
    ));

    $productFiltered = $this->reports->transactionSummary(new ReportFilters(productId: $outOfScope->id));

    expect($dateFiltered['stock_in'])->toBe(1009)
        ->and($typeFiltered['stock_out'])->toBe(4)
        ->and($typeFiltered['stock_in'])->toBe(0)
        ->and($scoped['stock_in'])->toBe(17)
        ->and($scoped['stock_out'])->toBe(4)
        ->and($productFiltered['stock_in'])->toBe(999);
});

test('transaction report search matches product name and sku', function () {
    $matching = makeProduct(['name' => 'Thermal Printer', 'sku' => 'SKU-PRN-0001']);
    $other = makeProduct(['name' => 'Desk Lamp', 'sku' => 'SKU-LMP-0001']);

    InventoryTransaction::factory()->for($matching)->stockIn()->create(['user_id' => $this->admin->id, 'quantity' => 3]);
    InventoryTransaction::factory()->for($other)->stockIn()->create(['user_id' => $this->admin->id, 'quantity' => 9]);

    expect($this->reports->transactionSummary(new ReportFilters(search: 'Thermal'))['stock_in'])->toBe(3)
        ->and($this->reports->transactionSummary(new ReportFilters(search: 'SKU-LMP'))['stock_in'])->toBe(9);
});

// ─── Supplier Performance ─────────────────────────────────────────────────────

test('supplier performance aggregates products, stock, value and low stock counts', function () {
    makeProduct([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 10,
        'minimum_stock' => 2,
        'cost_price' => 30.00,
    ]);

    makeProduct([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 1,
        'minimum_stock' => 5,
        'cost_price' => 10.00,
    ]);

    makeProduct([
        'supplier_id' => $this->otherSupplier->id,
        'category_id' => $this->otherCategory->id,
        'current_stock' => 2,
        'minimum_stock' => 1,
        'cost_price' => 5.00,
    ]);

    $ranked = $this->reports->supplierPerformanceQuery(new ReportFilters)->get()->keyBy('name');

    expect((int) $ranked['Apex Supply']->products_supplied)->toBe(2)
        ->and((int) $ranked['Apex Supply']->total_units)->toBe(11)
        ->and((float) $ranked['Apex Supply']->total_value)->toBe(310.00)
        ->and((int) $ranked['Apex Supply']->low_stock_products)->toBe(1)
        ->and((float) $ranked['Globex Supply']->total_value)->toBe(10.00)
        ->and($ranked->keys()->first())->toBe('Apex Supply'); // ranked by value
});

test('supplier performance includes suppliers without products and summarises totals', function () {
    $emptySupplier = Supplier::factory()->create(['name' => 'Zeta Supply']);

    makeProduct([
        'supplier_id' => $this->supplier->id,
        'current_stock' => 3,
        'minimum_stock' => 1,
        'cost_price' => 20.00,
    ]);

    $rows = $this->reports->supplierPerformanceQuery(new ReportFilters)->get()->keyBy('name');
    $summary = $this->reports->supplierPerformanceSummary(new ReportFilters);

    expect((int) $rows['Zeta Supply']->products_supplied)->toBe(0)
        ->and((float) $rows['Zeta Supply']->total_value)->toBe(0.0)
        ->and($summary['supplier_count'])->toBe(3)
        ->and($summary['product_count'])->toBe(1)
        ->and($summary['total_units'])->toBe(3)
        ->and($summary['total_value'])->toBe(60.00)
        ->and($summary['top_supplier'])->toBe($this->supplier->name)
        ->and($emptySupplier->exists)->toBeTrue();
});

test('supplier performance can be filtered by supplier and search', function () {
    makeProduct(['supplier_id' => $this->supplier->id, 'current_stock' => 2, 'cost_price' => 10.00]);
    makeProduct(['supplier_id' => $this->otherSupplier->id, 'current_stock' => 8, 'cost_price' => 10.00]);

    $single = $this->reports->supplierPerformanceQuery(new ReportFilters(supplierId: $this->supplier->id))->get();
    $searched = $this->reports->supplierPerformanceQuery(new ReportFilters(search: 'Globex'))->get();

    expect($single)->toHaveCount(1)
        ->and($single->first()->name)->toBe('Apex Supply')
        ->and($searched)->toHaveCount(1)
        ->and($searched->first()->name)->toBe('Globex Supply');
});

// ─── Component rendering & filters ────────────────────────────────────────────

test('admin can view the reports page', function () {
    $this->actingAs($this->admin)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Advanced Reports');
});

test('every report type renders successfully', function (string $type) {
    makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 4,
        'minimum_stock' => 10,
        'cost_price' => 15.00,
    ]);

    InventoryTransaction::factory()->create([
        'product_id' => Product::first()->id,
        'user_id' => $this->admin->id,
        'type' => 'stock_in',
        'quantity' => 5,
        'transaction_date' => now()->subDay(),
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::reports')
        ->call('selectReport', $type)
        ->assertSet('reportType', $type)
        ->assertHasNoErrors()
        ->assertOk();
})->with(['valuation', 'low_stock', 'dead_stock', 'transactions', 'supplier_performance']);

test('component filters narrow the rendered rows', function () {
    $matching = makeProduct([
        'name' => 'Filtered Widget',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 2,
        'minimum_stock' => 10,
    ]);

    $hidden = makeProduct([
        'name' => 'Hidden Widget',
        'category_id' => $this->otherCategory->id,
        'supplier_id' => $this->otherSupplier->id,
        'current_stock' => 1,
        'minimum_stock' => 10,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::reports')
        ->call('selectReport', 'low_stock')
        ->set('categoryId', (string) $this->category->id)
        ->assertSee($matching->sku)
        ->assertDontSee($hidden->sku)
        ->set('search', 'Hidden')
        ->assertDontSee($matching->sku);
});

test('reset filters clears all active filters', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::reports')
        ->set('search', 'widget')
        ->set('categoryId', (string) $this->category->id)
        ->set('supplierId', (string) $this->supplier->id)
        ->set('severity', 'critical')
        ->set('transactionType', 'stock_in')
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-02-01')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('categoryId', '')
        ->assertSet('supplierId', '')
        ->assertSet('severity', '')
        ->assertSet('transactionType', '')
        ->assertSet('startDate', '')
        ->assertSet('endDate', '')
        ->assertSet('inactivityDays', 30);
});

test('invalid report type falls back to valuation', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::reports')
        ->call('selectReport', 'not_a_report')
        ->assertSet('reportType', 'valuation');
});

// ─── Exports ──────────────────────────────────────────────────────────────────

test('csv export contains the filtered rows and totals', function () {
    $included = makeProduct([
        'name' => 'Included Product',
        'sku' => 'SKU-INC-0001',
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 3,
        'cost_price' => 10.00,
        'selling_price' => 20.00,
    ]);

    makeProduct([
        'name' => 'Excluded Product',
        'sku' => 'SKU-EXC-0001',
        'category_id' => $this->otherCategory->id,
        'supplier_id' => $this->otherSupplier->id,
        'current_stock' => 7,
        'cost_price' => 10.00,
        'selling_price' => 20.00,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::reports')
        ->set('categoryId', (string) $this->category->id)
        ->call('exportCsv')
        ->assertFileDownloaded();

    $csv = $this->exporter->csv('valuation', new ReportFilters(categoryId: $this->category->id));
    ob_start();
    $csv->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain('SKU-INC-0001')
        ->and($content)->not->toContain('SKU-EXC-0001')
        ->and($content)->toContain('TOTALS (1 products)')
        ->and($content)->toContain($included->name)
        ->and($csv->headers->get('content-disposition'))->toContain('sims_valuation_report_');
});

test('csv export covers every report type', function (string $type) {
    $product = makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 2,
        'minimum_stock' => 10,
        'cost_price' => 12.50,
    ]);

    InventoryTransaction::factory()->for($product)->stockIn()->create([
        'user_id' => $this->admin->id,
        'quantity' => 6,
        'transaction_date' => now()->subDays(45),
    ]);

    $response = $this->exporter->csv($type, new ReportFilters);

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    $expectedNeedle = $type === 'supplier_performance' ? $this->supplier->name : $product->sku;

    expect($content)->toContain($expectedNeedle)
        ->and($content)->toContain('TOTALS')
        ->and($response->headers->get('content-disposition'))->toContain('sims_'.$type.'_report_');
})->with(['valuation', 'low_stock', 'dead_stock', 'transactions', 'supplier_performance']);

test('csv export honours the low stock severity filter', function () {
    $critical = makeProduct(['sku' => 'SKU-CRT-0001', 'current_stock' => 0, 'minimum_stock' => 10]);
    $low = makeProduct(['sku' => 'SKU-LOW-0001', 'current_stock' => 9, 'minimum_stock' => 10]);

    $response = $this->exporter->csv('low_stock', new ReportFilters(severity: 'critical'));

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($content)->toContain($critical->sku)
        ->and($content)->not->toContain($low->sku)
        ->and($content)->toContain('CRITICAL');
});

test('pdf export is generated for the active report and filters', function () {
    makeProduct([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 5,
        'cost_price' => 20.00,
    ]);

    $this->actingAs($this->admin);

    $response = $this->exporter->pdf('valuation', new ReportFilters(categoryId: $this->category->id), 'Test Operator');

    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('sims_valuation_report_')
        ->and($content)->toStartWith('%PDF');
});

test('export filter summary describes the applied filters', function () {
    $summary = $this->exporter->filterSummary(new ReportFilters(
        search: 'widget',
        categoryId: $this->category->id,
        supplierId: $this->supplier->id,
        severity: 'critical',
        transactionType: 'stock_in',
    ));

    expect($summary)->toContain('Search: "widget"')
        ->and($summary)->toContain('Category: Electronics')
        ->and($summary)->toContain('Supplier: Apex Supply')
        ->and($summary)->toContain('Severity: Critical')
        ->and($summary)->toContain('Type: STOCK IN')
        ->and($this->exporter->filterSummary(new ReportFilters))->toBe('None');
});

// ─── Authorization ────────────────────────────────────────────────────────────

test('staff can view and export reports', function () {
    $this->actingAs($this->staff)
        ->get(route('reports.index'))
        ->assertOk();

    Livewire::actingAs($this->staff)
        ->test('pages::reports')
        ->assertSet('reportType', 'valuation')
        ->call('exportCsv')
        ->assertOk();
});

test('supplier role cannot access the reports page', function () {
    $this->actingAs($this->supplierUser)
        ->get(route('reports.index'))
        ->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('users without the export permission cannot export', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo('view reports');

    $this->actingAs($viewer)
        ->get(route('reports.index'))
        ->assertOk();

    Livewire::actingAs($viewer)
        ->test('pages::reports')
        ->call('exportCsv')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::reports')
        ->call('exportPdf')
        ->assertForbidden();
});

test('export buttons are hidden for users without export permission', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    $viewer->givePermissionTo('view reports');

    Livewire::actingAs($viewer)
        ->test('pages::reports')
        ->assertDontSeeHtml('wire:click="exportCsv"');

    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->assertSeeHtml('wire:click="exportCsv"');
});

// ─── Performance ──────────────────────────────────────────────────────────────

test('report queries stay constant regardless of dataset size', function () {
    Product::factory()->count(25)->create([
        'category_id' => $this->category->id,
        'supplier_id' => $this->supplier->id,
        'current_stock' => 3,
        'minimum_stock' => 10,
        'cost_price' => 5.00,
    ]);

    Product::all()->each(function (Product $product) {
        InventoryTransaction::factory()->for($product)->stockIn()->create([
            'user_id' => $this->admin->id,
            'quantity' => 4,
            'transaction_date' => now()->subDays(2),
        ]);
    });

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::reports');

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $component->call('selectReport', 'transactions')->assertOk();

    // 26 transactions render with a fixed query budget: one aggregate, the
    // pagination count, the page itself and one batched query per eager loaded
    // relation. An N+1 regression would push this well past the threshold.
    expect($queries)->toBeLessThan(15);
});
