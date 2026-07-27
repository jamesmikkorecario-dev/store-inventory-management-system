<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\ReportExporter;
use App\Services\ReportFilters;
use Database\Seeders\ForecastPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the roles and permissions the forecasting module relies on.
 */
function seedForecastRoles(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['Admin', 'Staff', 'Supplier'] as $role) {
        Role::findOrCreate($role);
    }

    foreach (['view reports', 'export reports', 'view purchase orders', 'manage purchase orders'] as $permission) {
        Permission::findOrCreate($permission);
    }

    Role::findByName('Admin')->givePermissionTo(['view reports', 'export reports', 'view purchase orders', 'manage purchase orders']);
    Role::findByName('Staff')->givePermissionTo(['view reports', 'export reports', 'view purchase orders', 'manage purchase orders']);

    (new ForecastPermissionsSeeder)->run();
}

/**
 * Record a stock out transaction without touching the InventoryService guards,
 * so tests can position usage precisely inside or outside the analysis window.
 */
function recordUsage(Product $product, int $quantity, int $daysAgo, User $user): InventoryTransaction
{
    return InventoryTransaction::create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'type' => 'stock_out',
        'quantity' => $quantity,
        'unit_cost' => $product->cost_price,
        'unit_price' => $product->selling_price,
        'remarks' => 'Test consumption',
        'transaction_date' => Carbon::now()->subDays($daysAgo),
    ]);
}

beforeEach(function () {
    seedForecastRoles();

    $this->forecasts = app(ForecastService::class);
    $this->supplier = Supplier::factory()->create(['status' => 'active']);
    $this->category = Category::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');

    // 60 units consumed over the last 30 days => 2/day, 100 in stock => 50 days cover.
    $this->product = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'name' => 'Forecast Widget',
        'cost_price' => 10.00,
        'current_stock' => 100,
        'minimum_stock' => 20,
        'status' => 'active',
    ]);

    recordUsage($this->product, 30, 5, $this->admin);
    recordUsage($this->product, 30, 20, $this->admin);
});

function forecastOf(mixed $test, Product $product, int $days = 30): array
{
    $row = $test->forecasts->forecastQuery(new ReportFilters(productId: $product->id, forecastDays: $days))->firstOrFail();

    return $test->forecasts->forecastFor($row, $days);
}

// ─── Calculations ─────────────────────────────────────────────────────────────

test('it calculates average daily weekly and monthly usage', function () {
    $forecast = forecastOf($this, $this->product);

    expect($forecast['has_data'])->toBeTrue()
        ->and($forecast['usage_units'])->toBe(60)
        ->and($forecast['average_daily_usage'])->toBe(2.0)
        ->and($forecast['average_weekly_usage'])->toBe(14.0)
        ->and($forecast['average_monthly_usage'])->toBe(60.0);
});

test('it calculates days until stockout and the forecasted stockout date', function () {
    $forecast = forecastOf($this, $this->product);

    // 100 units at 2/day.
    expect($forecast['days_remaining'])->toBe(50.0)
        ->and($forecast['stockout_date']->toDateString())
        ->toBe(Carbon::now()->startOfDay()->addDays(50)->toDateString());
});

test('it derives the suggested reorder date from the supplier lead time', function () {
    $forecast = forecastOf($this, $this->product);

    expect($forecast['suggested_reorder_date']->toDateString())
        ->toBe(Carbon::now()->startOfDay()->addDays(50 - ForecastService::LEAD_TIME_DAYS)->toDateString());
});

test('the suggested reorder quantity covers projected demand plus safety stock', function () {
    // 2/day over 37 days of cover = 74 target, minus 100 on hand => nothing needed.
    expect(forecastOf($this, $this->product)['suggested_reorder_quantity'])->toBe(0);

    $this->product->update(['current_stock' => 10]);

    // Target 74, on hand 10 => 64.
    expect(forecastOf($this, $this->product)['suggested_reorder_quantity'])->toBe(64);
});

test('the suggested reorder quantity respects the product minimum stock', function () {
    // Tiny usage so projected demand falls under the minimum stock floor.
    $product = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 0,
        'minimum_stock' => 40,
    ]);

    recordUsage($product, 1, 3, $this->admin);

    // Projected demand ceil(1/30 * 37) = 2, but the minimum stock floor of 40 wins.
    expect(forecastOf($this, $product)['suggested_reorder_quantity'])->toBe(40);
});

test('the suggested reorder quantity is never negative', function () {
    $product = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 5000,
        'minimum_stock' => 10,
    ]);

    recordUsage($product, 30, 2, $this->admin);

    expect(forecastOf($this, $product)['suggested_reorder_quantity'])->toBe(0)
        ->and($this->forecasts->suggestedReorderQuantity(5000, 10, 2.0))->toBe(0);
});

test('the analysis period changes the projection', function () {
    // Only the 5-day-old movement of 30 units falls inside a 7 day window.
    $sevenDay = forecastOf($this, $this->product, 7);

    expect($sevenDay['usage_units'])->toBe(30)
        ->and($sevenDay['average_daily_usage'])->toBe(round(30 / 7, 4))
        ->and($sevenDay['days_remaining'])->toBeLessThan(30.0);

    $ninetyDay = forecastOf($this, $this->product, 90);

    expect($ninetyDay['usage_units'])->toBe(60)
        ->and($ninetyDay['average_daily_usage'])->toBe(round(60 / 90, 4));
});

test('it normalises unsupported analysis periods to the default', function () {
    expect($this->forecasts->periodDays(45))->toBe(ForecastService::DEFAULT_PERIOD)
        ->and($this->forecasts->periodDays(null))->toBe(ForecastService::DEFAULT_PERIOD)
        ->and($this->forecasts->periodDays(7))->toBe(7)
        ->and($this->forecasts->periodDays(60))->toBe(60)
        ->and($this->forecasts->periodDays(90))->toBe(90);
});

// ─── Edge cases ───────────────────────────────────────────────────────────────

test('a product with no transaction history reports insufficient data', function () {
    $product = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 50,
        'minimum_stock' => 5,
    ]);

    $forecast = forecastOf($this, $product);

    expect($forecast['has_data'])->toBeFalse()
        ->and($forecast['usage_units'])->toBe(0)
        ->and($forecast['average_daily_usage'])->toBe(0.0)
        ->and($forecast['days_remaining'])->toBeNull()
        ->and($forecast['stockout_date'])->toBeNull()
        ->and($forecast['suggested_reorder_date'])->toBeNull()
        ->and($forecast['days_remaining_label'])->toBe(ForecastService::INSUFFICIENT_DATA)
        ->and($forecast['severity'])->toBe('unknown')
        ->and($forecast['severity_label'])->toBe(ForecastService::INSUFFICIENT_DATA);
});

test('a product with incoming stock but no outgoing movement cannot be projected', function () {
    $product = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 80,
        'minimum_stock' => 10,
    ]);

    InventoryTransaction::factory()->stockIn()->create([
        'product_id' => $product->id,
        'user_id' => $this->admin->id,
        'quantity' => 80,
        'transaction_date' => Carbon::now()->subDays(3),
    ]);

    $forecast = forecastOf($this, $product);

    expect($forecast['has_data'])->toBeFalse()
        ->and($forecast['days_remaining'])->toBeNull()
        ->and($forecast['severity'])->toBe('unknown');
});

test('usage outside the analysis window is ignored', function () {
    $product = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 40,
        'minimum_stock' => 5,
    ]);

    recordUsage($product, 90, 80, $this->admin);

    expect(forecastOf($this, $product, 30)['has_data'])->toBeFalse()
        ->and(forecastOf($this, $product, 90)['has_data'])->toBeTrue();
});

test('an out of stock product is flagged regardless of usage history', function () {
    $this->product->update(['current_stock' => 0]);

    $forecast = forecastOf($this, $this->product);

    expect($forecast['severity'])->toBe('out_of_stock')
        ->and($forecast['severity_label'])->toBe('Out of Stock')
        ->and($forecast['days_remaining'])->toBe(0.0);
});

test('severity buckets follow the configured thresholds', function () {
    expect($this->forecasts->severityFor(0, 0.0, 5.0))->toBe('out_of_stock')
        ->and($this->forecasts->severityFor(10, 5.0, 2.0))->toBe('critical')
        ->and($this->forecasts->severityFor(40, 20.0, 2.0))->toBe('warning')
        ->and($this->forecasts->severityFor(400, 200.0, 2.0))->toBe('healthy')
        ->and($this->forecasts->severityFor(40, null, 0.0))->toBe('unknown');
});

test('zero consumption yields no days remaining and no negative quantities', function () {
    expect($this->forecasts->averageDailyUsage(0, 30))->toBe(0.0)
        ->and($this->forecasts->daysRemaining(100, 0.0))->toBeNull()
        ->and($this->forecasts->suggestedReorderQuantity(100, 10, 0.0))->toBe(0);
});

// ─── Summary and ordering ─────────────────────────────────────────────────────

test('the forecast summary aggregates risk buckets and suggested units', function () {
    // Critical: 10 units at 2/day => 5 days.
    $critical = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 10,
        'minimum_stock' => 5,
    ]);
    recordUsage($critical, 60, 10, $this->admin);

    // No history at all.
    Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 25,
        'minimum_stock' => 0,
    ]);

    $summary = $this->forecasts->forecastSummary(new ReportFilters(forecastDays: 30));

    expect($summary['product_count'])->toBe(3)
        ->and($summary['forecastable'])->toBe(2)
        ->and($summary['insufficient_data'])->toBe(1)
        ->and($summary['critical'])->toBe(1)
        ->and($summary['at_risk'])->toBe(1)
        ->and($summary['suggested_units'])->toBeGreaterThan(0)
        ->and($summary['suggested_value'])->toBeGreaterThan(0.0);
});

test('the forecast query orders by nearest depletion with unknowns last', function () {
    $soonest = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'name' => 'Depletes First',
        'current_stock' => 4,
        'minimum_stock' => 5,
    ]);
    recordUsage($soonest, 60, 10, $this->admin);

    $noData = Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'name' => 'No History',
        'current_stock' => 10,
    ]);

    $ordered = $this->forecasts->forecastQuery(new ReportFilters(forecastDays: 30))->get();

    expect($ordered->first()->id)->toBe($soonest->id)
        ->and($ordered->last()->id)->toBe($noData->id);
});

test('upcoming stockouts exclude products without usage history', function () {
    Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 10,
    ]);

    $stockouts = $this->forecasts->upcomingStockouts(5, 30);

    expect($stockouts)->toHaveCount(1)
        ->and($stockouts->first()->id)->toBe($this->product->id);
});

test('the forecast query respects search category and supplier filters', function () {
    $otherSupplier = Supplier::factory()->create();
    $otherCategory = Category::factory()->create();

    $other = Product::factory()->create([
        'supplier_id' => $otherSupplier->id,
        'category_id' => $otherCategory->id,
        'name' => 'Unrelated Item',
    ]);
    recordUsage($other, 10, 2, $this->admin);

    expect($this->forecasts->forecastQuery(new ReportFilters(supplierId: $this->supplier->id))->count())->toBe(1)
        ->and($this->forecasts->forecastQuery(new ReportFilters(categoryId: $otherCategory->id))->count())->toBe(1)
        ->and($this->forecasts->forecastQuery(new ReportFilters(search: 'Unrelated'))->pluck('id')->all())->toBe([$other->id]);
});

// ─── Dashboard widget ─────────────────────────────────────────────────────────

test('the dashboard renders the forecasted stockouts widget for admins', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::dashboard')
        ->assertSet('showForecasts', true)
        ->assertSet('forecastPeriod', 30)
        ->assertSee('Forecasted Stockouts')
        ->assertSee('Forecast Widget');
});

test('the dashboard forecast widget switches analysis period', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::dashboard')
        ->call('switchForecastPeriod', 7)
        ->assertSet('forecastPeriod', 7)
        ->call('switchForecastPeriod', 90)
        ->assertSet('forecastPeriod', 90)
        ->call('switchForecastPeriod', 45)
        ->assertSet('forecastPeriod', ForecastService::DEFAULT_PERIOD);
});

test('the dashboard hides the forecast widget from users without the permission', function () {
    $supplierUser = User::factory()->create(['supplier_id' => $this->supplier->id]);
    $supplierUser->assignRole('Supplier');

    Livewire::actingAs($supplierUser)
        ->test('pages::dashboard')
        ->assertSet('showForecasts', false)
        ->assertDontSee('Forecasted Stockouts');
});

// ─── Forecast report ──────────────────────────────────────────────────────────

test('the reports page renders the inventory forecast tab', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'forecast')
        ->assertSet('reportType', 'forecast')
        ->assertSee('Inventory Forecast')
        ->assertSee('Forecast Widget')
        ->assertSee('Suggested Reorder');
});

test('the forecast report switches period and filters', function () {
    $other = Product::factory()->create([
        'supplier_id' => Supplier::factory()->create()->id,
        'category_id' => $this->category->id,
        'name' => 'Excluded Product',
    ]);
    recordUsage($other, 5, 1, $this->admin);

    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'forecast')
        ->assertSee('Excluded Product')
        ->set('supplierId', (string) $this->supplier->id)
        ->assertSee('Forecast Widget')
        ->assertDontSee('Excluded Product')
        ->set('forecastDays', 90)
        ->assertSet('forecastDays', 90);
});

test('the forecast report shows an insufficient data marker', function () {
    Product::factory()->create([
        'supplier_id' => $this->supplier->id,
        'category_id' => $this->category->id,
        'name' => 'Never Sold Item',
        'current_stock' => 12,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'forecast')
        ->assertSee('Never Sold Item')
        ->assertSee(ForecastService::INSUFFICIENT_DATA);
});

test('the forecast report resets the period with the other filters', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'forecast')
        ->set('forecastDays', 90)
        ->call('resetFilters')
        ->assertSet('forecastDays', 30);
});

// ─── Exports ──────────────────────────────────────────────────────────────────

test('the forecast report exports to csv respecting filters', function () {
    $excluded = Product::factory()->create([
        'supplier_id' => Supplier::factory()->create()->id,
        'category_id' => $this->category->id,
        'name' => 'Filtered Out Product',
    ]);
    recordUsage($excluded, 4, 1, $this->admin);

    $filters = new ReportFilters(supplierId: $this->supplier->id, forecastDays: 30);

    $response = app(ReportExporter::class)->csv('forecast', $filters);

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($response->headers->get('content-disposition'))->toContain('forecast')
        ->and($csv)->toContain('Forecast Widget')
        ->and($csv)->toContain('Suggested Reorder Qty')
        ->and($csv)->toContain('Avg Daily Usage')
        ->and($csv)->toContain('TOTALS')
        ->and($csv)->not->toContain('Filtered Out Product');
});

test('the forecast report exports to pdf', function () {
    $response = app(ReportExporter::class)->pdf('forecast', new ReportFilters(forecastDays: 30), 'Test Operator');

    ob_start();
    $response->sendContent();
    $pdf = (string) ob_get_clean();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdf)->toStartWith('%PDF');
});

test('the reports page streams the forecast csv download', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::reports')
        ->call('selectReport', 'forecast')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

// ─── Purchase order recommendations ───────────────────────────────────────────

test('the purchase orders page lists replenishment recommendations', function () {
    $this->product->update(['current_stock' => 8]);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->assertSet('canViewForecasts', true)
        ->call('toggleRecommendations')
        ->assertSet('showRecommendations', true)
        ->assertSee('Forecast Widget')
        ->assertSee('Replenishment Recommendations');
});

test('it generates a draft purchase order from selected recommendations', function () {
    $this->product->update(['current_stock' => 8]);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('toggleRecommendations')
        ->set('selectedRecommendations', [$this->product->id])
        ->call('generateDraftFromRecommendations')
        ->assertSet('selectedRecommendations', []);

    $order = PurchaseOrder::with('items')->firstOrFail();

    expect($order->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and($order->supplier_id)->toBe($this->supplier->id)
        ->and($order->created_by)->toBe($this->admin->id)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->product_id)->toBe($this->product->id)
        ->and($order->items->first()->quantity_ordered)->toBe(66)
        ->and((float) $order->total_amount)->toBe(660.00)
        ->and($order->notes)->toContain('forecast');
});

test('it raises one draft purchase order per supplier', function () {
    $secondSupplier = Supplier::factory()->create();
    $secondProduct = Product::factory()->create([
        'supplier_id' => $secondSupplier->id,
        'category_id' => $this->category->id,
        'current_stock' => 2,
        'minimum_stock' => 30,
    ]);
    recordUsage($secondProduct, 30, 4, $this->admin);

    $this->product->update(['current_stock' => 8]);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('toggleRecommendations')
        ->set('selectedRecommendations', [$this->product->id, $secondProduct->id])
        ->call('generateDraftFromRecommendations');

    expect(PurchaseOrder::count())->toBe(2)
        ->and(PurchaseOrder::pluck('supplier_id')->sort()->values()->all())
        ->toBe(collect([$this->supplier->id, $secondSupplier->id])->sort()->values()->all());
});

test('generating a draft with nothing selected does not create an order', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('toggleRecommendations')
        ->call('generateDraftFromRecommendations');

    expect(PurchaseOrder::count())->toBe(0);
});

test('generated purchase orders follow the standard workflow', function () {
    $this->product->update(['current_stock' => 8]);

    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('toggleRecommendations')
        ->set('selectedRecommendations', [$this->product->id])
        ->call('generateDraftFromRecommendations');

    $order = PurchaseOrder::firstOrFail();

    expect($order->po_number)->toStartWith('PO-')
        ->and($order->isEditable())->toBeTrue()
        ->and($order->canTransitionTo(PurchaseOrder::STATUS_SUBMITTED))->toBeTrue();
});

test('the recommendation period selector is normalised', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::purchase-orders')
        ->call('switchRecommendationPeriod', 90)
        ->assertSet('recommendationPeriod', 90)
        ->call('switchRecommendationPeriod', 45)
        ->assertSet('recommendationPeriod', ForecastService::DEFAULT_PERIOD);
});

// ─── Permissions ──────────────────────────────────────────────────────────────

test('admin and staff both hold the forecast permissions', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    foreach ([$this->admin, $staff] as $user) {
        expect($user->can('view forecasts'))->toBeTrue()
            ->and($user->can('export forecasts'))->toBeTrue();
    }
});

test('suppliers hold no forecast permissions', function () {
    $supplierUser = User::factory()->create(['supplier_id' => $this->supplier->id]);
    $supplierUser->assignRole('Supplier');

    expect($supplierUser->can('view forecasts'))->toBeFalse()
        ->and($supplierUser->can('export forecasts'))->toBeFalse();
});

test('the forecast report tab is blocked without the view permission', function () {
    Role::findByName('Staff')->revokePermissionTo('view forecasts');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    Livewire::actingAs($staff)
        ->test('pages::reports')
        ->assertDontSee('Inventory Forecast')
        ->call('selectReport', 'forecast')
        ->assertForbidden();
});

test('the forecast export is blocked without the export permission', function () {
    Role::findByName('Staff')->revokePermissionTo('export forecasts');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    Livewire::actingAs($staff)
        ->test('pages::reports')
        ->call('selectReport', 'forecast')
        ->call('exportCsv')
        ->assertForbidden();
});

test('recommendation actions are blocked without the forecast permission', function () {
    Role::findByName('Staff')->revokePermissionTo('view forecasts');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $staff = User::factory()->create();
    $staff->assignRole('Staff');

    Livewire::actingAs($staff)
        ->test('pages::purchase-orders')
        ->assertSet('canViewForecasts', false)
        ->call('toggleRecommendations')
        ->assertForbidden();

    expect(PurchaseOrder::count())->toBe(0);
});

test('the forecast permissions seeder is idempotent', function () {
    (new ForecastPermissionsSeeder)->run();
    (new ForecastPermissionsSeeder)->run();

    expect(Permission::where('name', 'view forecasts')->count())->toBe(1)
        ->and(Permission::where('name', 'export forecasts')->count())->toBe(1)
        ->and(Role::findByName('Admin')->hasPermissionTo('view forecasts'))->toBeTrue();
});
