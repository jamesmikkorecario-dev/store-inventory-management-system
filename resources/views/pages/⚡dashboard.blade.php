<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
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

    public function mount(): void
    {
        $user = Auth::user();
        $this->isSupplier = $user->hasRole('Supplier');
        $this->supplierId = $user->supplier_id;

        $this->loadStatistics();
    }

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

        <!-- Details Grid -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Low Stock Alerts Widget -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-3">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="bell" class="size-5 text-zinc-500" />
                        <flux:heading size="lg" class="font-semibold">Low Stock Alerts</flux:heading>
                    </div>
                    <flux:button variant="subtle" size="sm" href="{{ route('alerts.index') }}" wire:navigate>View All Alerts</flux:button>
                </div>
                <div class="mt-4 flex-1">
                    @forelse($latestAlerts as $alert)
                        <div class="flex items-center justify-between py-3 border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                            <div class="flex items-center gap-3">
                                @if($alert->severity === 'critical')
                                    <flux:badge color="rose" icon="exclamation-triangle" size="sm">Critical</flux:badge>
                                @else
                                    <flux:badge color="amber" icon="exclamation-circle" size="sm">Low</flux:badge>
                                @endif
                                <div>
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $alert->product->name ?? 'Unknown Product' }}</flux:text>
                                    <flux:text class="text-xs text-zinc-500">Stock: {{ $alert->current_stock }} / Min: {{ $alert->threshold }}</flux:text>
                                </div>
                            </div>
                            <flux:text class="text-xs text-zinc-400">{{ $alert->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-8 text-center border border-dashed rounded-lg border-zinc-200 dark:border-zinc-700 mt-2">
                            <flux:icon name="check-circle" class="size-8 text-emerald-500 mb-2" />
                            <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">No active alerts</flux:text>
                            <flux:text class="text-xs text-zinc-500 mt-1">Stock levels are looking good.</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Left: Low Stock Items List -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-1">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <flux:heading size="lg" class="font-semibold">Low Stock Alert</flux:heading>
                    <flux:badge variant="warning">{{ count($lowStockItems) }} listed</flux:badge>
                </div>
                <div class="mt-4 flex-1 space-y-4">
                    @forelse($lowStockItems as $item)
                        <div class="flex flex-col rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">{{ $item->name }}</flux:text>
                                    <flux:text class="block text-xs text-zinc-500">SKU: {{ $item->sku }}</flux:text>
                                </div>
                                <div class="text-right">
                                    <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:bg-rose-950/30 dark:text-rose-400">
                                        Stock: {{ $item->current_stock }}
                                    </span>
                                    <flux:text class="block text-[10px] text-zinc-400 mt-1">Min: {{ $item->minimum_stock }}</flux:text>
                                </div>
                            </div>
                            @php
                                $percentage = $item->minimum_stock > 0 ? min(100, round(($item->current_stock / $item->minimum_stock) * 100)) : 100;
                            @endphp
                            <div class="mt-3">
                                <flux:progress :value="$percentage" />
                            </div>
                        </div>
                    @empty
                        <div class="flex h-full flex-col items-center justify-center py-8 text-center">
                            <flux:icon name="check-circle" class="size-8 text-emerald-500 mb-2" />
                            <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">No Low Stock Items</flux:text>
                            <flux:text class="text-xs text-zinc-500">All products are within optimal levels.</flux:text>
                        </div>
                    @endforelse
                </div>
                @if($lowStockCount > count($lowStockItems))
                    <div class="mt-4 border-t border-zinc-150 pt-4 dark:border-zinc-800 text-center">
                        <flux:link href="{{ route('products.index') }}" class="text-sm font-medium">View all {{ $lowStockCount }} low stock items</flux:link>
                    </div>
                @endif
            </div>

            <!-- Right: Recent Transactions List -->
            <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="flex items-center justify-between border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <flux:heading size="lg" class="font-semibold">
                        {{ $isSupplier ? 'Recent Delivery History' : 'Recent Transactions' }}
                    </flux:heading>
                    <flux:link href="{{ route('transactions.index') }}" class="text-sm font-medium">View all</flux:link>
                </div>
                <div class="mt-4 flex-1 overflow-x-auto">
                    <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                        <thead>
                            <tr class="border-b border-zinc-100 text-xs font-semibold text-zinc-400 dark:border-zinc-800">
                                <th scope="col" class="pb-3">Product</th>
                                <th scope="col" class="pb-3">Type</th>
                                <th scope="col" class="pb-3">Quantity</th>
                                <th scope="col" class="pb-3">Remarks</th>
                                <th scope="col" class="pb-3">User</th>
                                <th scope="col" class="pb-3 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/50">
                            @forelse($recentTransactions as $tx)
                                <tr>
                                    <td class="py-3">
                                        <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">{{ $tx->product->name ?? 'Deleted Product' }}</flux:text>
                                        <flux:text class="block text-xs text-zinc-400">{{ $tx->product->sku ?? '' }}</flux:text>
                                    </td>
                                    <td class="py-3">
                                        @if($tx->type === 'stock_in')
                                            <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-400">Stock In</span>
                                        @elseif($tx->type === 'stock_out')
                                            <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-600 dark:bg-rose-950/30 dark:text-rose-400">Stock Out</span>
                                        @else
                                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Adjustment</span>
                                        @endif
                                    </td>
                                    <td class="py-3 font-semibold {{ $tx->type === 'stock_in' ? 'text-emerald-600' : ($tx->type === 'stock_out' ? 'text-rose-600' : 'text-zinc-600 dark:text-zinc-300') }}">
                                        {{ $tx->type === 'stock_in' ? '+' : ($tx->type === 'stock_out' ? '-' : ($tx->quantity >= 0 ? '+' : '')) }}{{ abs($tx->quantity) }}
                                    </td>
                                    <td class="py-3 text-zinc-500 max-w-[200px] truncate" title="{{ $tx->remarks }}">{{ $tx->remarks ?: '-' }}</td>
                                    <td class="py-3">
                                        @if($tx->user)
                                            <div class="flex items-center gap-2">
                                                <flux:avatar size="xs" :initials="$tx->user->initials()" />
                                                <flux:text class="text-xs text-zinc-500">{{ $tx->user->name }}</flux:text>
                                            </div>
                                        @else
                                            <flux:text class="text-xs text-zinc-500">System</flux:text>
                                        @endif
                                    </td>
                                    <td class="py-3 text-right text-zinc-400 text-xs">{{ $tx->transaction_date->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-zinc-500">No transactions recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Inventory Overview -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="font-semibold mb-4">Inventory Overview</flux:heading>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <!-- Total Stock Units -->
                <div class="text-center">
                    <flux:text class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($totalStockUnits) }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">Total Units</flux:text>
                </div>
                <!-- Active Products -->
                <div class="text-center">
                    <flux:text class="text-2xl font-bold text-emerald-600">{{ $activeProducts }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">Active Products</flux:text>
                </div>
                <!-- Inactive Products -->
                <div class="text-center">
                    <flux:text class="text-2xl font-bold text-zinc-400">{{ $inactiveProducts }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">Inactive</flux:text>
                </div>
                <!-- Out of Stock -->
                <div class="text-center">
                    <flux:text class="text-2xl font-bold text-rose-600">{{ $outOfStockCount }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">Out of Stock</flux:text>
                </div>
            </div>
        </div>

        @if(!$isSupplier && count($recentSuppliers) > 0)
            <!-- Recently Added Suppliers -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg" class="font-semibold mb-4">Recently Added Suppliers</flux:heading>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    @foreach($recentSuppliers as $supplier)
                        <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/50 flex flex-col justify-center">
                            <flux:heading size="sm" class="font-medium truncate">{{ $supplier->name }}</flux:heading>
                            <flux:text class="text-xs text-zinc-500 truncate mt-1">{{ $supplier->email }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

