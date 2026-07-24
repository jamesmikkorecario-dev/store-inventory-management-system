<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\InventoryTransaction;
use App\Services\InventoryAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public int $totalProducts = 0;
    public int $totalSuppliers = 0;
    public int $totalUsers = 0;
    public float $inventoryValue = 0.0;
    public int $lowStockCount = 0;
    public $lowStockItems = [];
    public $latestAlerts = [];
    public $recentTransactions = [];
    public bool $isSupplier = false;
    public ?int $supplierId = null;

    public int $totalStockUnits = 0;
    public int $activeProducts = 0;
    public int $inactiveProducts = 0;
    public int $outOfStockCount = 0;
    public $recentSuppliers = [];

    // Analytics properties
    public int $analyticsPeriod = 30;
    public array $stockMovementTrend = ['labels' => [], 'stock_in' => [], 'stock_out' => []];
    public array $activityOverview = ['labels' => [], 'activity' => []];
    public $topMovingProducts = [];
    public $slowMovingProducts = [];
    public array $inventoryHealth = [
        'healthy' => 0, 'low_stock' => 0, 'out_of_stock' => 0,
        'healthy_pct' => 0.0, 'low_stock_pct' => 0.0, 'out_of_stock_pct' => 0.0,
        'total' => 0,
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $this->isSupplier = $user->hasRole('Supplier');
        $this->supplierId = $user->supplier_id;

        $this->loadStatistics();
        $this->loadAnalytics();
    }

    #[On('products-updated')]
    #[On('transactions-updated')]
    #[On('suppliers-updated')]
    #[On('alerts-updated')]
    #[On('users-updated')]
    #[On('inventory-updated')]
    #[On('dashboard-updated')]
    public function loadStatistics(): void
    {
        if ($this->isSupplier && $this->supplierId) {
            // Load Supplier-specific stats
            $productQuery = Product::where('supplier_id', $this->supplierId);
            $this->totalProducts = (clone $productQuery)->count();
            
            // Value of supplier's inventory
            $this->inventoryValue = (clone $productQuery)->get()->sum(function ($p) {
                return $p->current_stock * $p->cost_price;
            });

            // Low stock products from this supplier
            $lowStockQuery = (clone $productQuery)->whereColumn('current_stock', '<=', 'minimum_stock');
            $this->lowStockCount = (clone $lowStockQuery)->count();
            $this->lowStockItems = (clone $lowStockQuery)->limit(5)->get();

            // Recent deliveries (stock_in) for this supplier's products
            $productIds = $productQuery->pluck('id')->toArray();
            $this->recentTransactions = InventoryTransaction::with(['product', 'user'])
                ->whereIn('product_id', $productIds)
                ->where('type', 'stock_in')
                ->latest('transaction_date')
                ->limit(5)
                ->get();

            $this->totalStockUnits = (clone $productQuery)->sum('current_stock');
            $this->activeProducts = (clone $productQuery)->where('status', 'active')->count();
            $this->inactiveProducts = (clone $productQuery)->where('status', 'inactive')->count();
            $this->outOfStockCount = (clone $productQuery)->where('current_stock', 0)->count();

        } else {
            // Load admin/staff global stats
            $this->totalProducts = Product::count();
            $this->totalSuppliers = Supplier::count();
            $this->totalUsers = User::count();

            $this->inventoryValue = Product::get()->sum(function ($p) {
                return $p->current_stock * $p->cost_price;
            });

            $this->lowStockCount = Product::whereColumn('current_stock', '<=', 'minimum_stock')->count();
            $this->lowStockItems = Product::with(['supplier', 'category'])
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->limit(5)
                ->get();

            $this->recentTransactions = InventoryTransaction::with(['product', 'user'])
                ->latest('transaction_date')
                ->limit(5)
                ->get();
                
            $this->totalStockUnits = Product::sum('current_stock');
            $this->activeProducts = Product::where('status', 'active')->count();
            $this->inactiveProducts = Product::where('status', 'inactive')->count();
            $this->outOfStockCount = Product::where('current_stock', 0)->count();
            
            $this->recentSuppliers = Supplier::latest()->limit(5)->get();
            
            $this->latestAlerts = app(\App\Services\LowStockNotificationService::class)->getLatestActiveAlerts(5);
        }
    }

    public function loadAnalytics(): void
    {
        $analytics = app(InventoryAnalyticsService::class);
        $sid = $this->isSupplier ? $this->supplierId : null;

        $this->stockMovementTrend = $analytics->getStockMovementTrend($this->analyticsPeriod, $sid);
        $this->activityOverview = $analytics->getInventoryActivityOverview($this->analyticsPeriod, $sid);
        $this->topMovingProducts = $analytics->getTopMovingProducts($this->analyticsPeriod, $sid);
        $this->slowMovingProducts = $analytics->getSlowMovingProducts($this->analyticsPeriod, $sid);
        $this->inventoryHealth = $analytics->getInventoryHealth($sid);
    }

    public function switchPeriod(int $days): void
    {
        $this->analyticsPeriod = $days;
        $this->loadAnalytics();
        $this->dispatch('analytics-period-changed', 
            trend: $this->stockMovementTrend, 
            activity: $this->activityOverview
        );
    }

    #[On('products-updated')]
    #[On('transactions-updated')]
    #[On('inventory-updated')]
    #[On('alerts-updated')]
    public function refreshAnalytics(): void
    {
        $this->loadAnalytics();
    }
}; ?>

    <div class="space-y-6">
        <!-- Title & Welcoming -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">Dashboard</flux:heading>
                <flux:subheading>Welcome back, {{ auth()->user()->name }} (Role: {{ auth()->user()->roles->pluck('name')->first() }})</flux:subheading>
            </div>
            <flux:text class="text-sm text-zinc-500">{{ now()->format('l, F j, Y') }}</flux:text>
        </div>

        <!-- Quick Actions Bar -->
        <div class="flex flex-wrap gap-3">
            @if(!$isSupplier)
                <flux:button variant="primary" size="sm" icon="plus" href="{{ route('products.index') }}" wire:navigate>New Product</flux:button>
                <flux:button variant="filled" size="sm" icon="arrows-right-left" href="{{ route('transactions.index') }}" wire:navigate>Record Transaction</flux:button>
                <flux:button variant="filled" size="sm" icon="arrow-trending-up" href="{{ route('reports.index') }}" wire:navigate>View Reports</flux:button>
            @else
                <flux:button variant="primary" size="sm" icon="archive-box" href="{{ route('products.index') }}" wire:navigate>View Products</flux:button>
                <flux:button variant="filled" size="sm" icon="clock" href="{{ route('transactions.index') }}" wire:navigate>View Delivery History</flux:button>
            @endif
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Products Card -->
            <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Total Products</flux:text>
                    <flux:icon name="archive-box" class="size-6 text-indigo-500" />
                </div>
                <div class="mt-4 flex items-baseline justify-between">
                    <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $totalProducts }}</flux:heading>
                    <span class="text-xs text-zinc-500">Active catalog</span>
                </div>
            </div>

            <!-- Inventory Value Card -->
            <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Inventory Value (Cost)</flux:text>
                    <flux:icon name="banknotes" class="size-6 text-emerald-500" />
                </div>
                <div class="mt-4 flex items-baseline justify-between">
                    <flux:heading size="xl" class="font-bold text-zinc-950 dark:text-white">${{ number_format($inventoryValue, 2) }}</flux:heading>
                    <span class="text-xs text-zinc-500">Asset value</span>
                </div>
            </div>

            <!-- Low Stock Card -->
            <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 {{ $lowStockCount > 0 ? 'ring-1 ring-amber-500/50' : '' }}">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Low Stock Items</flux:text>
                    <flux:icon name="exclamation-triangle" class="size-6 text-amber-500" />
                </div>
                <div class="mt-4 flex items-baseline justify-between">
                    <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $lowStockCount }}</flux:heading>
                    <span class="text-xs {{ $lowStockCount > 0 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-zinc-500' }}">
                        {{ $lowStockCount > 0 ? 'Requires attention' : 'All stocked up' }}
                    </span>
                </div>
            </div>

            @if(!$isSupplier)
                <!-- Total Suppliers Card -->
                <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Total Suppliers</flux:text>
                        <flux:icon name="truck" class="size-6 text-pink-500" />
                    </div>
                    <div class="mt-4 flex items-baseline justify-between">
                        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $totalSuppliers }}</flux:heading>
                        <span class="text-xs text-zinc-500">Partners</span>
                    </div>
                </div>
            @else
                <!-- Linked Supplier Info -->
                <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Assigned Supplier</flux:text>
                        <flux:icon name="briefcase" class="size-6 text-pink-500" />
                    </div>
                    <div class="mt-4">
                        <flux:heading class="truncate font-semibold text-zinc-900 dark:text-white" size="lg">
                            {{ auth()->user()->supplier->name ?? 'N/A' }}
                        </flux:heading>
                        <flux:text class="block truncate text-xs text-zinc-500">
                            {{ auth()->user()->supplier->email ?? '' }}
                        </flux:text>
                    </div>
                </div>
            @endif
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- INVENTORY ANALYTICS SECTION                        -->
        <!-- ═══════════════════════════════════════════════════ -->

        <!-- Period Filter -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <flux:icon name="chart-bar" class="size-5 text-indigo-500" />
                <flux:heading size="lg" class="font-semibold">Inventory Analytics</flux:heading>
            </div>
            <div class="inline-flex items-center gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                <button type="button"
                    wire:click="switchPeriod(7)"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-all duration-150 cursor-pointer {{ $analyticsPeriod === 7 ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                    7 Days
                </button>
                <button type="button"
                    wire:click="switchPeriod(30)"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-all duration-150 cursor-pointer {{ $analyticsPeriod === 30 ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                    30 Days
                </button>
                <button type="button"
                    wire:click="switchPeriod(90)"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-all duration-150 cursor-pointer {{ $analyticsPeriod === 90 ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                    90 Days
                </button>
            </div>
        </div>

        <!-- Inventory Health Widget -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                <flux:icon name="heart" class="size-5 text-emerald-500" />
                <flux:heading size="lg" class="font-semibold">Inventory Health</flux:heading>
            </div>
            <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-3">
                <!-- Healthy -->
                <div class="flex h-full flex-col items-center justify-center rounded-2xl border border-zinc-200 bg-emerald-50 p-6 text-center transition-all duration-200 hover:-translate-y-0.5 hover:shadow-sm hover:border-zinc-300 dark:border-zinc-700 dark:bg-emerald-900/20 dark:hover:border-zinc-600">
                    <flux:icon name="check-circle" variant="solid" class="size-10 text-emerald-500 dark:text-emerald-400" />
                    <flux:text class="mt-4 text-4xl font-bold text-emerald-700 dark:text-emerald-400">{{ $inventoryHealth['healthy'] }}</flux:text>
                    <flux:text class="mt-1 text-sm font-semibold text-emerald-700 dark:text-emerald-500">Healthy Products</flux:text>
                    <flux:text class="mt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $inventoryHealth['healthy_pct'] }}% of total</flux:text>
                </div>
                <!-- Low Stock -->
                <div class="flex h-full flex-col items-center justify-center rounded-2xl border border-zinc-200 bg-amber-50 p-6 text-center transition-all duration-200 hover:-translate-y-0.5 hover:shadow-sm hover:border-zinc-300 dark:border-zinc-700 dark:bg-amber-900/20 dark:hover:border-zinc-600">
                    <flux:icon name="exclamation-triangle" variant="solid" class="size-10 text-amber-500 dark:text-amber-400" />
                    <flux:text class="mt-4 text-4xl font-bold text-amber-700 dark:text-amber-400">{{ $inventoryHealth['low_stock'] }}</flux:text>
                    <flux:text class="mt-1 text-sm font-semibold text-amber-700 dark:text-amber-500">Low Stock Products</flux:text>
                    <flux:text class="mt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $inventoryHealth['low_stock_pct'] }}% of total</flux:text>
                </div>
                <!-- Out of Stock -->
                <div class="flex h-full flex-col items-center justify-center rounded-2xl border border-zinc-200 bg-rose-50 p-6 text-center transition-all duration-200 hover:-translate-y-0.5 hover:shadow-sm hover:border-zinc-300 dark:border-zinc-700 dark:bg-rose-900/20 dark:hover:border-zinc-600">
                    <flux:icon name="x-circle" variant="solid" class="size-10 text-rose-500 dark:text-rose-400" />
                    <flux:text class="mt-4 text-4xl font-bold text-rose-700 dark:text-rose-400">{{ $inventoryHealth['out_of_stock'] }}</flux:text>
                    <flux:text class="mt-1 text-sm font-semibold text-rose-700 dark:text-rose-500">Out of Stock</flux:text>
                    <flux:text class="mt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $inventoryHealth['out_of_stock_pct'] }}% of total</flux:text>
                </div>
            </div>
            @if($inventoryHealth['total'] > 0)
                <!-- Health Bar -->
                <div class="mt-6 flex h-4 w-full overflow-hidden rounded-full bg-zinc-100 shadow-inner dark:bg-zinc-800/80">
                    @if($inventoryHealth['healthy_pct'] > 0)
                        <div class="bg-emerald-500 transition-all duration-500 ease-in-out hover:opacity-90" style="width: {{ $inventoryHealth['healthy_pct'] }}%" title="Healthy: {{ $inventoryHealth['healthy'] }}"></div>
                    @endif
                    @if($inventoryHealth['low_stock_pct'] > 0)
                        <div class="bg-amber-500 transition-all duration-500 ease-in-out hover:opacity-90" style="width: {{ $inventoryHealth['low_stock_pct'] }}%" title="Low Stock: {{ $inventoryHealth['low_stock'] }}"></div>
                    @endif
                    @if($inventoryHealth['out_of_stock_pct'] > 0)
                        <div class="bg-rose-500 transition-all duration-500 ease-in-out hover:opacity-90" style="width: {{ $inventoryHealth['out_of_stock_pct'] }}%" title="Out of Stock: {{ $inventoryHealth['out_of_stock'] }}"></div>
                    @endif
                </div>
                <div class="mt-3 flex items-center justify-center gap-6 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500"></span> Healthy</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-500"></span> Low Stock</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-rose-500"></span> Out of Stock</span>
                </div>
            @else
                <div class="mt-6 flex flex-col items-center justify-center py-6 text-center border border-dashed rounded-lg border-zinc-200 dark:border-zinc-700">
                    <flux:icon name="archive-box" class="size-8 text-zinc-400 mb-2" />
                    <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">No active products</flux:text>
                    <flux:text class="text-xs text-zinc-500 mt-1">Add products to see inventory health.</flux:text>
                </div>
            @endif
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Stock Movement Trend Chart -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                 x-data="stockMovementChart"
                 wire:ignore>
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="arrow-trending-up" class="size-5 text-indigo-500" />
                        <flux:heading size="lg" class="font-semibold">Stock Movement Trend</flux:heading>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-zinc-500">
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500"></span> Stock In</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-rose-500"></span> Stock Out</span>
                    </div>
                </div>
                <div class="mt-4" style="height: 280px;">
                    <canvas id="stockMovementChart"></canvas>
                </div>
            </div>

            <!-- Inventory Activity Overview Chart -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                 x-data="activityOverviewChart"
                 wire:ignore>
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="chart-bar" class="size-5 text-violet-500" />
                        <flux:heading size="lg" class="font-semibold">Inventory Activity Overview</flux:heading>
                    </div>
                </div>
                <div class="mt-4" style="height: 280px;">
                    <canvas id="activityOverviewChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Product Performance Widgets -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Top Moving Products -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="fire" class="size-5 text-orange-500" />
                        <flux:heading size="lg" class="font-semibold">Top Moving Products</flux:heading>
                    </div>
                    <flux:badge variant="pill" color="indigo" size="sm">Last {{ $analyticsPeriod }} days</flux:badge>
                </div>
                <div class="mt-4 flex flex-1 flex-col">
                    @forelse($topMovingProducts as $index => $product)
                        <div class="flex items-center justify-between py-3 border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                            <div class="flex items-center gap-3">
                                <span class="flex size-7 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600 dark:bg-indigo-950/30 dark:text-indigo-400">{{ $index + 1 }}</span>
                                <div>
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $product->name }}</flux:text>
                                    <flux:text class="text-xs text-zinc-500">Stock: {{ $product->current_stock }} units</flux:text>
                                </div>
                            </div>
                            <div class="text-right">
                                <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $product->total_movements }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">movements</flux:text>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center py-8 text-center border border-dashed rounded-lg border-zinc-200 dark:border-zinc-700 mt-2">
                            <flux:icon name="arrow-trending-up" class="size-8 text-zinc-400 mb-2" />
                            <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">No movements yet</flux:text>
                            <flux:text class="text-xs text-zinc-500 mt-1">Record transactions to see top movers.</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Slow Moving Products -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="clock" class="size-5 text-zinc-500" />
                        <flux:heading size="lg" class="font-semibold">Slow Moving Products</flux:heading>
                    </div>
                    <flux:badge variant="pill" color="zinc" size="sm">Needs attention</flux:badge>
                </div>
                <div class="mt-4 flex flex-1 flex-col">
                    @forelse($slowMovingProducts as $product)
                        <div class="flex items-center justify-between py-3 border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                            <div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $product->name }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">Stock: {{ $product->current_stock }} units</flux:text>
                            </div>
                            <div class="text-right">
                                @if($product->days_since_last_transaction !== null)
                                    <flux:text class="font-semibold {{ $product->days_since_last_transaction > 30 ? 'text-rose-600 dark:text-rose-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        {{ $product->days_since_last_transaction }}d
                                    </flux:text>
                                    <flux:text class="text-xs text-zinc-500">since last tx</flux:text>
                                @else
                                    <flux:badge color="rose" size="sm">Never moved</flux:badge>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center py-8 text-center border border-dashed rounded-lg border-zinc-200 dark:border-zinc-700 mt-2">
                            <flux:icon name="check-circle" class="size-8 text-emerald-500 mb-2" />
                            <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">All products moving</flux:text>
                            <flux:text class="text-xs text-zinc-500 mt-1">No slow-moving products detected.</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        @if(auth()->user()->hasAnyRole(['Admin', 'Staff']))
        <!-- Details Grid -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Low Stock Alerts Widget -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="bell" class="size-5 text-zinc-500 dark:text-zinc-400" />
                        <flux:heading size="lg" class="font-semibold">Low Stock Alerts</flux:heading>
                    </div>
                    <flux:button variant="subtle" size="sm" href="{{ route('alerts.index') }}" wire:navigate>View all</flux:button>
                </div>
                <div class="mt-2 flex flex-1 flex-col">
                    @forelse($latestAlerts as $alert)
                        <div class="flex items-center justify-between py-4 border-b border-zinc-100 last:border-0 dark:border-zinc-800/70 transition-colors hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 px-2 -mx-2 rounded-lg">
                            <div class="flex items-center gap-4">
                                @if($alert->severity === 'critical')
                                    <flux:badge color="rose" icon="exclamation-triangle" size="sm" class="w-24 justify-center">Critical</flux:badge>
                                @else
                                    <flux:badge color="amber" icon="exclamation-circle" size="sm" class="w-24 justify-center">Low</flux:badge>
                                @endif
                                <div>
                                    <flux:text class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $alert->product->name ?? 'Unknown Product' }}</flux:text>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <flux:text class="text-xs text-zinc-500">Stock: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $alert->current_stock }}</span></flux:text>
                                        <span class="text-zinc-300 dark:text-zinc-600">&bull;</span>
                                        <flux:text class="text-xs text-zinc-500">Min: {{ $alert->threshold }}</flux:text>
                                    </div>
                                </div>
                            </div>
                            <flux:text class="text-[10px] uppercase tracking-wider text-zinc-400 font-medium">{{ $alert->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center py-10 text-center">
                            <flux:icon name="check-circle" class="size-10 text-emerald-500 mb-3" />
                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">No active alerts</flux:text>
                            <flux:text class="text-sm text-zinc-500 mt-1">Stock levels are looking good.</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Left: Low Stock Items List -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-1">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="exclamation-triangle" class="size-5 text-zinc-500 dark:text-zinc-400" />
                        <flux:heading size="lg" class="font-semibold">Low Stock Items</flux:heading>
                    </div>
                    <flux:badge variant="warning">{{ count($lowStockItems) }} listed</flux:badge>
                </div>
                <div class="mt-4 flex flex-1 flex-col space-y-3">
                    @forelse($lowStockItems as $item)
                        <div class="flex flex-col rounded-xl border border-zinc-100 bg-zinc-50/80 p-4 transition-colors hover:border-zinc-200 dark:border-zinc-800/80 dark:bg-zinc-800/20 dark:hover:border-zinc-700">
                            <div class="flex items-start justify-between">
                                <div class="flex flex-col">
                                    <flux:text class="font-semibold text-zinc-900 dark:text-zinc-100 truncate pr-2">{{ $item->name }}</flux:text>
                                    <flux:text class="mt-0.5 text-xs text-zinc-500 uppercase tracking-wider font-medium">{{ $item->sku }}</flux:text>
                                </div>
                                <div class="flex flex-col items-end whitespace-nowrap">
                                    <span class="rounded bg-rose-100/80 px-2 py-0.5 text-xs font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-400">
                                        {{ $item->current_stock }} left
                                    </span>
                                    <flux:text class="mt-1 text-[10px] font-medium text-zinc-400 uppercase tracking-wider">Min: {{ $item->minimum_stock }}</flux:text>
                                </div>
                            </div>
                            @php
                                $percentage = $item->minimum_stock > 0 ? min(100, round(($item->current_stock / $item->minimum_stock) * 100)) : 100;
                            @endphp
                            <div class="mt-4 flex h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div class="bg-rose-500 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center py-10 text-center">
                            <flux:icon name="check-circle" class="size-10 text-emerald-500 mb-3" />
                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">No Low Stock Items</flux:text>
                            <flux:text class="text-sm text-zinc-500 mt-1">All products are optimal.</flux:text>
                        </div>
                    @endforelse
                </div>
                @if($lowStockCount > count($lowStockItems))
                    <div class="mt-5 text-center">
                        <flux:button variant="subtle" class="w-full" href="{{ route('products.index') }}" wire:navigate>View all {{ $lowStockCount }} items</flux:button>
                    </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Recent Transactions List -->
        <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="arrows-right-left" class="size-5 text-zinc-500 dark:text-zinc-400" />
                        <flux:heading size="lg" class="font-semibold">
                            {{ $isSupplier ? 'Recent Delivery History' : 'Recent Transactions' }}
                        </flux:heading>
                    </div>
                    <flux:button variant="subtle" size="sm" href="{{ route('transactions.index') }}" wire:navigate>View all</flux:button>
                </div>
                <div class="mt-4 flex-1 overflow-x-auto">
                    <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                        <thead>
                            <tr class="border-b border-zinc-200 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <th scope="col" class="pb-3 pt-2 font-medium">Product</th>
                                <th scope="col" class="pb-3 pt-2 font-medium">Type</th>
                                <th scope="col" class="pb-3 pt-2 font-medium">Quantity</th>
                                <th scope="col" class="pb-3 pt-2 font-medium">Remarks</th>
                                <th scope="col" class="pb-3 pt-2 font-medium">User</th>
                                <th scope="col" class="pb-3 pt-2 text-right font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/70">
                            @forelse($recentTransactions as $tx)
                                <tr class="transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40 group">
                                    <td class="py-4 align-middle pr-4">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $tx->product->name ?? 'Deleted Product' }}</flux:text>
                                        <flux:text class="mt-0.5 block text-[11px] font-medium uppercase tracking-wider text-zinc-400">{{ $tx->product->sku ?? '' }}</flux:text>
                                    </td>
                                    <td class="py-4 align-middle pr-4">
                                        @if($tx->type === 'stock_in')
                                            <flux:badge color="emerald" size="sm" class="w-24 justify-center">Stock In</flux:badge>
                                        @elseif($tx->type === 'stock_out')
                                            <flux:badge color="rose" size="sm" class="w-24 justify-center">Stock Out</flux:badge>
                                        @else
                                            <flux:badge color="zinc" size="sm" class="w-24 justify-center">Adjustment</flux:badge>
                                        @endif
                                    </td>
                                    <td class="py-4 align-middle pr-4 font-bold {{ $tx->type === 'stock_in' ? 'text-emerald-600 dark:text-emerald-500' : ($tx->type === 'stock_out' ? 'text-rose-600 dark:text-rose-500' : 'text-zinc-700 dark:text-zinc-300') }}">
                                        {{ $tx->type === 'stock_in' ? '+' : ($tx->type === 'stock_out' ? '-' : ($tx->quantity >= 0 ? '+' : '')) }}{{ abs($tx->quantity) }}
                                    </td>
                                    <td class="py-4 align-middle pr-4 text-zinc-500 max-w-[200px] truncate" title="{{ $tx->remarks }}">{{ $tx->remarks ?: '-' }}</td>
                                    <td class="py-4 align-middle pr-4">
                                        @if($tx->user)
                                            <div class="flex items-center gap-2.5">
                                                <flux:avatar size="xs" :initials="$tx->user->initials()" />
                                                <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $tx->user->name }}</flux:text>
                                            </div>
                                        @else
                                            <flux:text class="text-sm font-medium text-zinc-500">System</flux:text>
                                        @endif
                                    </td>
                                    <td class="py-4 align-middle text-right text-[11px] font-medium uppercase tracking-wider text-zinc-400">
                                        {{ $tx->transaction_date->format('M d, Y') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center">
                                        <flux:icon name="document-text" class="size-10 text-zinc-300 dark:text-zinc-600 mx-auto mb-3" />
                                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">No transactions yet</flux:text>
                                        <flux:text class="text-sm text-zinc-500 mt-1">Transaction history will appear here.</flux:text>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- Inventory Overview -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon name="chart-pie" class="size-5 text-zinc-500 dark:text-zinc-400" />
                    <flux:heading size="lg" class="font-semibold">Inventory Overview</flux:heading>
                </div>
            </div>
            <div class="mt-8 mb-4 grid grid-cols-2 gap-8 sm:grid-cols-4">
                <!-- Total Stock Units -->
                <div class="flex flex-col items-center justify-center">
                    <flux:text class="text-4xl font-bold text-zinc-900 dark:text-white">{{ number_format($totalStockUnits) }}</flux:text>
                    <flux:text class="mt-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Total Units</flux:text>
                </div>
                <!-- Active Products -->
                <div class="flex flex-col items-center justify-center">
                    <flux:text class="text-4xl font-bold text-emerald-600 dark:text-emerald-500">{{ $activeProducts }}</flux:text>
                    <flux:text class="mt-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Active Products</flux:text>
                </div>
                <!-- Inactive Products -->
                <div class="flex flex-col items-center justify-center">
                    <flux:text class="text-4xl font-bold text-zinc-400 dark:text-zinc-500">{{ $inactiveProducts }}</flux:text>
                    <flux:text class="mt-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Inactive Products</flux:text>
                </div>
                <!-- Out of Stock -->
                <div class="flex flex-col items-center justify-center">
                    <flux:text class="text-4xl font-bold text-rose-600 dark:text-rose-500">{{ $outOfStockCount }}</flux:text>
                    <flux:text class="mt-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Out of Stock</flux:text>
                </div>
            </div>
        </div>

        @if(!$isSupplier && count($recentSuppliers) > 0)
            <!-- Recently Added Suppliers -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="building-office" class="size-5 text-zinc-500 dark:text-zinc-400" />
                        <flux:heading size="lg" class="font-semibold">Recently Added Suppliers</flux:heading>
                    </div>
                    <flux:button variant="subtle" size="sm" href="{{ route('suppliers.index') }}" wire:navigate>View all</flux:button>
                </div>
                <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    @foreach($recentSuppliers as $supplier)
                        <div class="flex h-full flex-col justify-center rounded-xl border border-zinc-200 bg-zinc-50 p-5 transition-colors hover:border-zinc-300 dark:border-zinc-700/80 dark:bg-zinc-800/40 dark:hover:border-zinc-600">
                            <flux:heading size="sm" class="font-semibold text-zinc-900 dark:text-zinc-100 truncate">{{ $supplier->name }}</flux:heading>
                            <flux:text class="mt-1 text-[11px] font-medium text-zinc-500 truncate dark:text-zinc-400">{{ $supplier->email ?: 'No email provided' }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @script
        <script>
            // Chart.js is now bundled via Vite in app.js

        // Convert Alpine reactive proxy to plain JS object for Chart.js compatibility
        function toRaw(data) {
            if (!data) return data;
            try {
                return JSON.parse(JSON.stringify(data));
            } catch (e) {
                return data;
            }
        }

        // Detect dark mode
        function isDarkMode() {
            return document.documentElement.classList.contains('dark');
        }

        function getGridColor() {
            return isDarkMode() ? 'rgba(113, 113, 122, 0.2)' : 'rgba(228, 228, 231, 0.8)';
        }

        function getTickColor() {
            return isDarkMode() ? 'rgba(161, 161, 170, 0.8)' : 'rgba(113, 113, 122, 0.8)';
        }

        Alpine.data('stockMovementChart', () => ({
            chart: null,
            init() {
                Livewire.on('analytics-period-changed', (eventData) => {
                    const raw = toRaw(eventData);
                    if (raw?.trend) {
                        this.renderChart(raw.trend);
                    }
                });
                this.ensureChartLoaded(() => {
                    this.renderChart(toRaw($wire.stockMovementTrend));
                });
            },
            ensureChartLoaded(cb) {
                if (typeof Chart !== 'undefined') {
                    cb();
                } else {
                    setTimeout(() => this.ensureChartLoaded(cb), 50);
                }
            },
            renderChart(data) {
                const ctx = document.getElementById('stockMovementChart');
                if (!ctx || !data) return;
                if (this.chart) {
                    this.chart.destroy();
                }
                this.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels || [],
                        datasets: [
                            {
                                label: 'Stock In',
                                data: data.stock_in || [],
                                borderColor: 'rgb(16, 185, 129)',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                borderWidth: 2,
                            },
                            {
                                label: 'Stock Out',
                                data: data.stock_out || [],
                                borderColor: 'rgb(244, 63, 94)',
                                backgroundColor: 'rgba(244, 63, 94, 0.1)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                borderWidth: 2,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { intersect: false, mode: 'index' },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: isDarkMode() ? '#27272a' : '#fff',
                                titleColor: isDarkMode() ? '#fafafa' : '#18181b',
                                bodyColor: isDarkMode() ? '#d4d4d8' : '#3f3f46',
                                borderColor: isDarkMode() ? '#27272a' : '#e4e4e7',
                                borderWidth: 1,
                                padding: 12,
                                cornerRadius: 8,
                            },
                        },
                        scales: {
                            x: {
                                grid: { color: getGridColor() },
                                ticks: { color: getTickColor(), maxTicksLimit: 8, font: { size: 11 } },
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: getGridColor() },
                                ticks: { color: getTickColor(), font: { size: 11 } },
                            },
                        },
                    },
                });
            },
        }));

        Alpine.data('activityOverviewChart', () => ({
            chart: null,
            init() {
                Livewire.on('analytics-period-changed', (eventData) => {
                    const raw = toRaw(eventData);
                    if (raw?.activity) {
                        this.renderChart(raw.activity);
                    }
                });
                this.ensureChartLoaded(() => {
                    this.renderChart(toRaw($wire.activityOverview));
                });
            },
            ensureChartLoaded(cb) {
                if (typeof Chart !== 'undefined') {
                    cb();
                } else {
                    setTimeout(() => this.ensureChartLoaded(cb), 50);
                }
            },
            renderChart(data) {
                const ctx = document.getElementById('activityOverviewChart');
                if (!ctx || !data) return;
                if (this.chart) {
                    this.chart.destroy();
                }
                this.chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels || [],
                        datasets: [
                            {
                                label: 'Transactions',
                                data: data.activity || [],
                                backgroundColor: isDarkMode()
                                    ? 'rgba(139, 92, 246, 0.6)'
                                    : 'rgba(139, 92, 246, 0.4)',
                                borderColor: 'rgb(139, 92, 246)',
                                borderWidth: 1,
                                borderRadius: 4,
                                hoverBackgroundColor: 'rgba(139, 92, 246, 0.8)',
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: isDarkMode() ? '#27272a' : '#fff',
                                titleColor: isDarkMode() ? '#fafafa' : '#18181b',
                                bodyColor: isDarkMode() ? '#d4d4d8' : '#3f3f46',
                                borderColor: isDarkMode() ? '#27272a' : '#e4e4e7',
                                borderWidth: 1,
                                padding: 12,
                                cornerRadius: 8,
                            },
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: getTickColor(), maxTicksLimit: 8, font: { size: 11 } },
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: getGridColor() },
                                ticks: { color: getTickColor(), stepSize: 1, font: { size: 11 } },
                            },
                        },
                    },
                });
            },
        }));
    </script>
    @endscript
</div>
