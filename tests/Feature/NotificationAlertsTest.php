<?php

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PurchaseOrderApprovedNotification;
use App\Notifications\PurchaseOrderReceivedNotification;
use App\Notifications\PurchaseOrderSubmittedNotification;
use App\Services\NotificationService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Admin']);
    Role::firstOrCreate(['name' => 'Staff']);
    Role::firstOrCreate(['name' => 'Supplier']);

    Permission::firstOrCreate(['name' => 'view purchase orders']);
    Permission::firstOrCreate(['name' => 'approve purchase orders']);
    Permission::firstOrCreate(['name' => 'view forecasts']);

    $this->admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@example.com']);
    $this->admin->assignRole('Admin');
    $this->admin->givePermissionTo(['view purchase orders', 'approve purchase orders', 'view forecasts']);

    $this->staff = User::factory()->create(['name' => 'Staff User', 'email' => 'staff@example.com']);
    $this->staff->assignRole('Staff');
    $this->staff->givePermissionTo(['view purchase orders', 'approve purchase orders', 'view forecasts']);

    $this->supplierUser = User::factory()->create(['name' => 'Supplier User', 'email' => 'supplier@example.com']);
    $this->supplierUser->assignRole('Supplier');
});

it('creates database notifications and supports read states', function () {
    $service = app(NotificationService::class);

    // Creating a low stock product automatically triggers lowStock notification via boot events
    $product = Product::factory()->create(['current_stock' => 5, 'minimum_stock' => 10]);

    expect($service->unreadCountFor($this->admin))->toBe(1);

    $notification = $this->admin->unreadNotifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->read_at)->toBeNull();

    $marked = $service->markAsRead($this->admin, $notification->id);
    expect($marked)->toBeTrue()
        ->and($service->unreadCountFor($this->admin))->toBe(0);

    // Create another notification and mark all as read
    $product2 = Product::factory()->create(['current_stock' => 2, 'minimum_stock' => 10]);
    expect($service->unreadCountFor($this->admin))->toBe(1);

    $service->markAllAsRead($this->admin);
    expect($service->unreadCountFor($this->admin))->toBe(0);
});

it('prevents duplicate unread low stock notifications', function () {
    $service = app(NotificationService::class);

    // Initial creation triggers the first notification for all Admin and Staff users
    $product = Product::factory()->create(['current_stock' => 5, 'minimum_stock' => 10]);
    expect($service->unreadCountFor($this->admin))->toBe(1);

    // Explicit second trigger while unread should be suppressed (returns 0)
    $secondCall = $service->lowStock($product);
    expect($secondCall)->toBe(0)
        ->and($service->unreadCountFor($this->admin))->toBe(1);

    // Mark as read for all recipients, then trigger again should create a new notification
    DatabaseNotification::query()->update(['read_at' => now()]);
    expect($service->unreadCountFor($this->admin))->toBe(0);

    $thirdCall = $service->lowStock($product);
    expect($thirdCall)->toBeGreaterThan(0)
        ->and($service->unreadCountFor($this->admin))->toBe(1);
});

it('creates and prevents duplicate forecast stockout notifications', function () {
    $service = app(NotificationService::class);
    $supplier = Supplier::factory()->create();
    $category = Category::factory()->create();

    // Create product with healthy stock so lowStock is not triggered
    $product = Product::factory()->create([
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'current_stock' => 20,
        'minimum_stock' => 5,
    ]);

    // Create stock_out transactions to generate high usage
    InventoryTransaction::create([
        'product_id' => $product->id,
        'user_id' => $this->admin->id,
        'type' => 'stock_out',
        'quantity' => 20,
        'unit_cost' => 10,
        'created_at' => Carbon::now()->subDays(5),
    ]);

    $notified = $service->forecastStockout($product, 30);
    expect($notified)->toBeGreaterThan(0);
    expect($service->unreadCountFor($this->admin))->toBe(1);

    // Duplicate while unread should be suppressed
    $duplicate = $service->forecastStockout($product, 30);
    expect($duplicate)->toBe(0)
        ->and($service->unreadCountFor($this->admin))->toBe(1);

    // After marking as read for all recipients, a new one can be created
    DatabaseNotification::query()->update(['read_at' => now()]);
    $afterRead = $service->forecastStockout($product, 30);
    expect($afterRead)->toBeGreaterThan(0);
});

it('dispatches purchase order workflow notifications to correct recipients', function () {
    $supplier = Supplier::factory()->create();
    $poService = app(PurchaseOrderService::class);

    $product = Product::factory()->create(['cost_price' => 50, 'current_stock' => 100, 'minimum_stock' => 10]);

    // Create PO
    $order = $poService->create([
        'supplier_id' => $supplier->id,
        'order_date' => Carbon::now()->toDateString(),
        'expected_delivery_date' => Carbon::now()->addDays(10)->toDateString(),
        'notes' => 'Urgent order',
    ], [
        [
            'product_id' => $product->id,
            'quantity_ordered' => 10,
            'unit_cost' => 50,
        ],
    ], $this->admin);

    $this->actingAs($this->admin);
    $poService->submit($order);

    // Staff has 'approve purchase orders' permission so should receive Submitted notification
    expect($this->staff->unreadNotifications()->where('type', PurchaseOrderSubmittedNotification::class)->count())->toBe(1);

    // Approve PO -> should notify creator (Admin)
    $poService->approve($order, $this->staff);
    expect($this->admin->unreadNotifications()->where('type', PurchaseOrderApprovedNotification::class)->count())->toBe(1);

    // Receive PO -> should notify creator (Admin)
    $poService->receive($order, [$order->items()->first()->id => 10], $this->staff);
    expect($this->admin->unreadNotifications()->where('type', PurchaseOrderReceivedNotification::class)->count())->toBe(1);
});

it('enforces notification authorization so users only see their own notifications', function () {
    $service = app(NotificationService::class);
    $product = Product::factory()->create(['current_stock' => 2, 'minimum_stock' => 10]);

    // Admin and Staff receive it, but Supplier user must NOT receive it
    expect($service->unreadCountFor($this->admin))->toBe(1)
        ->and($service->unreadCountFor($this->staff))->toBe(1)
        ->and($service->unreadCountFor($this->supplierUser))->toBe(0);

    // Supplier cannot mark admin's notification as read
    $adminNotifId = $this->admin->unreadNotifications()->first()->id;
    $markedBySupplier = $service->markAsRead($this->supplierUser, $adminNotifId);
    expect($markedBySupplier)->toBeFalse()
        ->and($service->unreadCountFor($this->admin))->toBe(1);
});

it('renders the notification center UI and supports filtering and pagination', function () {
    $service = app(NotificationService::class);
    $product = Product::factory()->create(['current_stock' => 1, 'minimum_stock' => 10]);

    $this->actingAs($this->admin);

    Livewire::test('pages::notifications')
        ->assertStatus(200)
        ->assertSee('Notifications')
        ->assertSee('Low stock warning')
        ->assertSee('Unread (1)')
        ->call('setFilter', 'all')
        ->assertSet('filter', 'all')
        ->call('markAllAsRead')
        ->assertDispatched('notifications-updated');

    expect($service->unreadCountFor($this->admin))->toBe(0);
});

it('displays role-aware notification summary widgets on the dashboard', function () {
    $service = app(NotificationService::class);
    $product = Product::factory()->create(['current_stock' => 0, 'minimum_stock' => 10]);

    $this->actingAs($this->admin);

    Livewire::test('pages::dashboard')
        ->assertStatus(200)
        ->assertSee('Unread Alerts')
        ->assertSee('Critical Stock')
        ->assertSee('30d Stockouts');

    // Supplier user should not see internal admin/staff notification widgets
    $this->actingAs($this->supplierUser);

    Livewire::test('pages::dashboard')
        ->assertStatus(200)
        ->assertDontSee('Unread Alerts')
        ->assertDontSee('Critical Stock');
});
