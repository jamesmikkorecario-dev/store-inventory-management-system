<?php

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\ReportExporter;
use App\Services\ReportFilters;
use App\Services\ReportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Advanced Reports')] class extends Component {
    use WithPagination;

    #[Url(as: 'report', keep: true)]
    public string $reportType = 'valuation';

    public string $search = '';
    public string $startDate = '';
    public string $endDate = '';
    public string $categoryId = '';
    public string $supplierId = '';
    public string $severity = '';
    public int $inactivityDays = 30;
    public string $transactionType = '';
    public string $productId = '';
    public string $purchaseOrderStatus = '';
    public int $perPage = 15;

    /**
     * Filter properties that must reset pagination when changed.
     *
     * @var list<string>
     */
    protected array $filterProperties = [
        'search',
        'startDate',
        'endDate',
        'categoryId',
        'supplierId',
        'severity',
        'inactivityDays',
        'transactionType',
        'productId',
        'purchaseOrderStatus',
        'perPage',
    ];

    public function mount(): void
    {
        // View authorization is handled by the `permission:view reports` route middleware.
        if (! array_key_exists($this->reportType, ReportService::REPORT_TYPES)) {
            $this->reportType = 'valuation';
        }
    }

    public function updated(string $property, mixed $value = null): void
    {
        if (in_array($property, $this->filterProperties, true)) {
            $this->resetPage();
        }
    }

    public function selectReport(string $type): void
    {
        $this->reportType = array_key_exists($type, ReportService::REPORT_TYPES) ? $type : 'valuation';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'startDate',
            'endDate',
            'categoryId',
            'supplierId',
            'severity',
            'transactionType',
            'productId',
            'purchaseOrderStatus',
        ]);

        $this->inactivityDays = 30;
        $this->resetPage();
    }

    public function exportCsv(): StreamedResponse
    {
        $this->authorizeExport();

        return app(ReportExporter::class)->csv($this->reportType, $this->filters());
    }

    public function exportPdf(): StreamedResponse
    {
        $this->authorizeExport();

        return app(ReportExporter::class)->pdf(
            $this->reportType,
            $this->filters(),
            Auth::user()?->name ?? 'Unknown operator',
        );
    }

    public function with(): array
    {
        $reports = app(ReportService::class);
        $filters = $this->filters();

        $shared = [
            'reportTypes' => ReportService::REPORT_TYPES,
            'inactivityPeriods' => ReportService::INACTIVITY_PERIODS,
            'categories' => Category::orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'purchaseOrderStatuses' => PurchaseOrder::STATUSES,
            'canExport' => Auth::user()?->can('export reports') ?? false,
            'hasActiveFilters' => $filters->hasAny(),
            'reportService' => $reports,
        ];

        return match ($this->reportType) {
            'low_stock' => [
                ...$shared,
                'summary' => $reports->lowStockSummary($filters),
                'rows' => $reports->lowStockQuery($filters)->paginate($this->perPage),
            ],
            'dead_stock' => [
                ...$shared,
                'summary' => $reports->deadStockSummary($filters),
                'rows' => $reports->deadStockQuery($filters)->paginate($this->perPage),
            ],
            'transactions' => [
                ...$shared,
                'summary' => $reports->transactionSummary($filters),
                'rows' => $reports->transactionQuery($filters)->paginate($this->perPage),
            ],
            'supplier_performance' => [
                ...$shared,
                'summary' => $reports->supplierPerformanceSummary($filters),
                'rows' => $reports->supplierPerformanceQuery($filters)->paginate($this->perPage),
            ],
            'purchase_orders' => [
                ...$shared,
                'summary' => $reports->purchaseOrderSummary($filters),
                'rows' => $reports->purchaseOrderQuery($filters)->paginate($this->perPage),
            ],
            'outstanding_orders' => [
                ...$shared,
                'summary' => $reports->outstandingOrderSummary($filters),
                'rows' => $reports->outstandingOrderQuery($filters)->paginate($this->perPage),
            ],
            'supplier_purchases' => [
                ...$shared,
                'summary' => $reports->supplierPurchaseSummary($filters),
                'rows' => $reports->supplierPurchaseQuery($filters)->paginate($this->perPage),
            ],
            default => [
                ...$shared,
                'summary' => $reports->valuationSummary($filters),
                'rows' => $reports->valuationQuery($filters)->paginate($this->perPage),
                'valueByCategory' => $reports->valuationByCategory($filters),
                'valueBySupplier' => $reports->valuationBySupplier($filters),
                'topProducts' => $reports->topValueProducts($filters),
            ],
        };
    }

    /**
     * Current filter state shared by the report queries and the exports.
     */
    protected function filters(): ReportFilters
    {
        return ReportFilters::fromArray([
            'search' => $this->search,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'categoryId' => $this->categoryId,
            'supplierId' => $this->supplierId,
            'severity' => $this->severity,
            'inactivityDays' => $this->inactivityDays,
            'transactionType' => $this->transactionType,
            'productId' => $this->productId,
            'purchaseOrderStatus' => $this->purchaseOrderStatus,
        ]);
    }

    protected function authorizeExport(): void
    {
        abort_unless(Auth::user()?->can('export reports') ?? false, 403);
    }
}; ?>

@php
    $loadingTargets = 'reportType, selectReport, search, startDate, endDate, categoryId, supplierId, severity, inactivityDays, transactionType, productId, purchaseOrderStatus, perPage, resetFilters, gotoPage, nextPage, previousPage';
    $purchaseOrderReports = ['purchase_orders', 'outstanding_orders', 'supplier_purchases'];
    $isPurchaseOrderReport = in_array($reportType, $purchaseOrderReports, true);
@endphp

<div class="space-y-6">
    <!-- Heading -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">Advanced Reports</flux:heading>
            <flux:subheading>Inventory valuation, stock risk, dead stock, movement, supplier performance and purchase order analytics.</flux:subheading>
        </div>
        @if($canExport)
            <div class="flex items-center gap-2">
                <flux:button wire:click="exportCsv" icon="arrow-down-tray" data-test="export-csv">Export CSV</flux:button>
                <flux:button wire:click="exportPdf" icon="printer" variant="primary" data-test="export-pdf">Export PDF</flux:button>
            </div>
        @endif
    </div>

    <!-- Report Type Tabs -->
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex min-w-max items-center gap-1">
            @foreach($reportTypes as $type => $label)
                <button
                    type="button"
                    wire:click="selectReport('{{ $type }}')"
                    data-test="tab-{{ $type }}"
                    @class([
                        'cursor-pointer whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $reportType === $type,
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $reportType !== $type,
                    ])
                    @if($reportType === $type) aria-current="page" @endif
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="grid grid-cols-1 items-end gap-4 rounded-xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="relative sm:col-span-2">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Search"
                placeholder="{{ $reportType === 'supplier_performance' ? 'Search supplier, contact, email...' : ($isPurchaseOrderReport ? 'Search PO number, supplier, notes...' : 'Search product name, SKU, identifier...') }}"
                icon="magnifying-glass"
            />
            <div wire:loading wire:target="search" class="absolute right-3 top-9">
                <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
            </div>
        </div>

        <div>
            <flux:input
                wire:model.live="startDate"
                type="date"
                :label="$reportType === 'transactions' ? 'Transactions From' : ($isPurchaseOrderReport ? 'Ordered From' : 'Records From')"
            />
        </div>
        <div>
            <flux:input
                wire:model.live="endDate"
                type="date"
                :label="$reportType === 'transactions' ? 'Transactions To' : ($isPurchaseOrderReport ? 'Ordered To' : 'Records To')"
            />
        </div>

        <div @class(['hidden' => $isPurchaseOrderReport])>
            <flux:select wire:model.live="categoryId" label="Category">
                <option value="">All Categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </flux:select>
        </div>

        <div>
            <flux:select wire:model.live="supplierId" label="Supplier">
                <option value="">All Suppliers</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </flux:select>
        </div>

        @if($reportType === 'low_stock')
            <div>
                <flux:select wire:model.live="severity" label="Severity">
                    <option value="">All Severities</option>
                    <option value="critical">Critical</option>
                    <option value="low">Low</option>
                </flux:select>
            </div>
        @endif

        @if($reportType === 'dead_stock')
            <div>
                <flux:select wire:model.live="inactivityDays" label="Inactivity Period">
                    @foreach($inactivityPeriods as $period)
                        <option value="{{ $period }}">No movement in {{ $period }} days</option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        @if($reportType === 'transactions')
            <div>
                <flux:select wire:model.live="transactionType" label="Transaction Type">
                    <option value="">All Types</option>
                    <option value="stock_in">Stock In</option>
                    <option value="stock_out">Stock Out</option>
                    <option value="adjustment">Adjustment</option>
                </flux:select>
            </div>
        @endif

        @if($reportType === 'purchase_orders' || $reportType === 'supplier_purchases')
            <div>
                <flux:select wire:model.live="purchaseOrderStatus" label="Order Status">
                    <option value="">All Statuses</option>
                    @foreach($purchaseOrderStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="flex items-center gap-2">
            <flux:select wire:model.live="perPage" label="Rows">
                <option value="15">15</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </flux:select>
        </div>

        <div class="sm:col-span-2 lg:col-span-4">
            <flux:button
                wire:click="resetFilters"
                icon="arrow-uturn-left"
                variant="{{ $hasActiveFilters ? 'filled' : 'ghost' }}"
                size="sm"
                data-test="reset-filters"
            >
                Reset Filters
            </flux:button>
            @if($hasActiveFilters)
                <flux:text class="ml-2 inline text-xs text-zinc-500 dark:text-zinc-400">Filters are applied to the table, summary and exports.</flux:text>
            @endif
        </div>
    </div>

    <div wire:loading wire:target="{{ $loadingTargets }}" class="flex w-full justify-center py-2">
        <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
    </div>

    <div class="space-y-6" wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $loadingTargets }}">
        <!-- Summary Cards -->
        @if($reportType === 'valuation')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Total Inventory Value" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['total_cost_value'], 2)" hint="Stock × cost price" />
                <x-report-stat-card label="Retail Value" icon="currency-dollar" icon-class="text-indigo-500" :value="'$' . number_format($summary['total_retail_value'], 2)" hint="Stock × selling price" />
                <x-report-stat-card label="Potential Profit" icon="arrow-trending-up" icon-class="text-sky-500" :value="'$' . number_format($summary['potential_profit'], 2)" hint="Retail − cost" />
                <x-report-stat-card label="Units In Stock" icon="cube" icon-class="text-amber-500" :value="number_format($summary['total_units'])" :hint="number_format($summary['product_count']) . ' products'" />
            </div>

            <!-- Value Breakdowns -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                @foreach([
                    ['title' => 'Value by Category', 'icon' => 'tag', 'data' => $valueByCategory],
                    ['title' => 'Value by Supplier', 'icon' => 'truck', 'data' => $valueBySupplier],
                ] as $breakdown)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                            <flux:icon :name="$breakdown['icon']" class="size-4 text-zinc-500" />
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $breakdown['title'] }}</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                                <thead>
                                    <tr class="border-b border-zinc-200 bg-zinc-50/50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950/40">
                                        <th scope="col" class="px-6 py-3">Name</th>
                                        <th scope="col" class="px-6 py-3 text-right">Products</th>
                                        <th scope="col" class="px-6 py-3 text-right">Units</th>
                                        <th scope="col" class="px-6 py-3 text-right">Value</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    @forelse($breakdown['data'] as $group)
                                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                            <td class="px-6 py-3 font-medium text-zinc-800 dark:text-zinc-200">{{ $group['label'] }}</td>
                                            <td class="px-6 py-3 text-right">{{ number_format($group['product_count']) }}</td>
                                            <td class="px-6 py-3 text-right">{{ number_format($group['total_units']) }}</td>
                                            <td class="px-6 py-3 text-right font-semibold">${{ number_format($group['total_value'], 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-6 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">No data for the current filters.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Top 10 Products -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <flux:icon name="trophy" class="size-4 text-amber-500" />
                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Top 10 Highest Value Products</span>
                </div>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($topProducts as $index => $product)
                        <div class="flex flex-col gap-1 px-6 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-[11px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $index + 1 }}</span>
                                <div>
                                    <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</flux:text>
                                    <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $product->sku }} · {{ $product->category->name ?? 'Uncategorized' }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-center gap-6 pl-9 sm:pl-0">
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ number_format($product->current_stock) }} units</span>
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">${{ number_format((float) $product->current_stock * (float) $product->cost_price, 2) }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">No products match the current filters.</div>
                    @endforelse
                </div>
            </div>
        @elseif($reportType === 'low_stock')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Products Below Minimum" icon="exclamation-triangle" icon-class="text-amber-500" :value="number_format($summary['total'])" hint="Needs replenishment" />
                <x-report-stat-card label="Critical" icon="exclamation-circle" icon-class="text-rose-500" :value="number_format($summary['critical'])" hint="At or under half of minimum" hint-class="text-rose-500" />
                <x-report-stat-card label="Low" icon="arrow-trending-down" icon-class="text-amber-500" :value="number_format($summary['low'])" hint="Approaching minimum" />
                <x-report-stat-card label="Units To Reorder" icon="shopping-cart" icon-class="text-indigo-500" :value="number_format($summary['units_required'])" :hint="number_format($summary['out_of_stock']) . ' out of stock'" />
            </div>
        @elseif($reportType === 'dead_stock')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Dead Stock Products" icon="archive-box-x-mark" icon-class="text-rose-500" :value="number_format($summary['total'])" :hint="'No movement in ' . $inactivityDays . ' days'" />
                <x-report-stat-card label="Units Sitting Idle" icon="cube" icon-class="text-amber-500" :value="number_format($summary['total_units'])" hint="On hand" />
                <x-report-stat-card label="Capital Tied Up" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['tied_value'], 2)" hint="Stock × cost price" />
                <x-report-stat-card label="Never Moved" icon="no-symbol" icon-class="text-zinc-400" :value="number_format($summary['never_moved'])" hint="No transactions on record" />
            </div>
        @elseif($reportType === 'transactions')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Stock In" icon="arrow-down-tray" icon-class="text-emerald-500" :value="'+' . number_format($summary['stock_in'])" :hint="'$' . number_format($summary['stock_in_value'], 2) . ' at cost'" />
                <x-report-stat-card label="Stock Out" icon="arrow-up-tray" icon-class="text-rose-500" :value="'-' . number_format($summary['stock_out'])" :hint="'$' . number_format($summary['stock_out_value'], 2) . ' at retail'" />
                <x-report-stat-card label="Adjustments" icon="adjustments-horizontal" icon-class="text-sky-500" :value="($summary['adjustments'] > 0 ? '+' : '') . number_format($summary['adjustments'])" hint="Signed corrections" />
                <x-report-stat-card
                    label="Net Movement"
                    icon="arrows-up-down"
                    :icon-class="$summary['net_movement'] >= 0 ? 'text-emerald-500' : 'text-rose-500'"
                    :value="($summary['net_movement'] > 0 ? '+' : '') . number_format($summary['net_movement'])"
                    :hint="number_format($summary['transaction_count']) . ' transactions'"
                />
            </div>
        @elseif($reportType === 'purchase_orders')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Purchase Orders" icon="clipboard-document-list" icon-class="text-indigo-500" :value="number_format($summary['order_count'])" :hint="number_format($summary['open_orders']) . ' still open'" />
                <x-report-stat-card label="Total Order Value" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['total_value'], 2)" :hint="'$' . number_format($summary['received_value'], 2) . ' received'" />
                <x-report-stat-card label="Units Ordered" icon="cube" icon-class="text-amber-500" :value="number_format($summary['units_ordered'])" :hint="number_format($summary['units_received']) . ' received'" />
                <x-report-stat-card
                    label="Overdue Orders"
                    icon="exclamation-triangle"
                    :icon-class="$summary['overdue_orders'] > 0 ? 'text-rose-500' : 'text-zinc-400'"
                    :value="number_format($summary['overdue_orders'])"
                    :hint="number_format($summary['cancelled_orders']) . ' cancelled'"
                    :hint-class="$summary['overdue_orders'] > 0 ? 'text-rose-500' : 'text-zinc-500'"
                />
            </div>
        @elseif($reportType === 'outstanding_orders')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Outstanding Orders" icon="clock" icon-class="text-amber-500" :value="number_format($summary['order_count'])" hint="Awaiting delivery" />
                <x-report-stat-card label="Units Outstanding" icon="cube" icon-class="text-indigo-500" :value="number_format($summary['units_outstanding'])" hint="Not yet received" />
                <x-report-stat-card label="Outstanding Value" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['outstanding_value'], 2)" hint="At agreed cost" />
                <x-report-stat-card
                    label="Overdue"
                    icon="exclamation-triangle"
                    :icon-class="$summary['overdue_orders'] > 0 ? 'text-rose-500' : 'text-zinc-400'"
                    :value="number_format($summary['overdue_orders'])"
                    :hint="number_format($summary['due_within_week']) . ' due within 7 days'"
                    :hint-class="$summary['overdue_orders'] > 0 ? 'text-rose-500' : 'text-zinc-500'"
                />
            </div>
        @elseif($reportType === 'supplier_purchases')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Suppliers Purchased From" icon="truck" icon-class="text-pink-500" :value="number_format($summary['supplier_count'])" :hint="$summary['top_supplier'] ? 'Top: ' . $summary['top_supplier'] : null" />
                <x-report-stat-card label="Purchase Orders" icon="clipboard-document-list" icon-class="text-indigo-500" :value="number_format($summary['order_count'])" hint="Across all suppliers" />
                <x-report-stat-card label="Purchase Value" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['total_value'], 2)" :hint="'$' . number_format($summary['received_value'], 2) . ' delivered'" />
                <x-report-stat-card label="Units Ordered" icon="cube" icon-class="text-amber-500" :value="number_format($summary['units_ordered'])" :hint="number_format($summary['units_received']) . ' received'" />
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-report-stat-card label="Suppliers Ranked" icon="truck" icon-class="text-pink-500" :value="number_format($summary['supplier_count'])" :hint="$summary['top_supplier'] ? 'Top: ' . $summary['top_supplier'] : null" />
                <x-report-stat-card label="Products Supplied" icon="archive-box" icon-class="text-indigo-500" :value="number_format($summary['product_count'])" hint="Across all suppliers" />
                <x-report-stat-card label="Total Stock" icon="cube" icon-class="text-amber-500" :value="number_format($summary['total_units'])" hint="Units on hand" />
                <x-report-stat-card label="Inventory Value" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['total_value'], 2)" :hint="number_format($summary['low_stock_products']) . ' low stock products'" />
            </div>
        @endif

        <!-- Report Table -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $reportTypes[$reportType] }} Report</span>
                <span class="text-xs text-zinc-400">{{ number_format($rows->total()) }} {{ Str::plural('record', $rows->total()) }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    @if($reportType === 'valuation')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">SKU / Product</th>
                                <th scope="col" class="px-6 py-4">Category</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4 text-right">Cost / Selling</th>
                                <th scope="col" class="px-6 py-4 text-right">Stock</th>
                                <th scope="col" class="px-6 py-4 text-right">Cost Value</th>
                                <th scope="col" class="px-6 py-4 text-right">Retail Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $product)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-6 py-3.5">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</flux:text>
                                        <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $product->sku }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $product->category->name ?? 'Uncategorized' }}</td>
                                    <td class="px-6 py-3.5">{{ $product->supplier->name ?? 'Unassigned' }}</td>
                                    <td class="px-6 py-3.5 text-right whitespace-nowrap">${{ number_format((float) $product->cost_price, 2) }} / ${{ number_format((float) $product->selling_price, 2) }}</td>
                                    <td class="px-6 py-3.5 text-right font-semibold">{{ number_format($product->current_stock) }}</td>
                                    <td class="px-6 py-3.5 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $product->current_stock * (float) $product->cost_price, 2) }}</td>
                                    <td class="px-6 py-3.5 text-right">${{ number_format((float) $product->current_stock * (float) $product->selling_price, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="banknotes" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No inventory to value</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Add products or relax the filters to see valuation figures.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['product_count']) }} products)</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['total_units']) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['total_cost_value'], 2) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['total_retail_value'], 2) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    @elseif($reportType === 'low_stock')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">SKU / Product</th>
                                <th scope="col" class="px-6 py-4">Category</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4 text-right">Current Stock</th>
                                <th scope="col" class="px-6 py-4 text-right">Minimum Stock</th>
                                <th scope="col" class="px-6 py-4 text-right">Shortfall</th>
                                <th scope="col" class="px-6 py-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $product)
                                @php($severity = $reportService->severityFor($product))
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-6 py-3.5">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</flux:text>
                                        <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $product->sku }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $product->category->name ?? 'Uncategorized' }}</td>
                                    <td class="px-6 py-3.5">{{ $product->supplier->name ?? 'Unassigned' }}</td>
                                    <td class="px-6 py-3.5 text-right font-bold {{ $severity === 'critical' ? 'text-rose-600 dark:text-rose-400' : 'text-amber-600 dark:text-amber-400' }}">{{ number_format($product->current_stock) }}</td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format($product->minimum_stock) }}</td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format(max($product->minimum_stock - $product->current_stock, 0)) }}</td>
                                    <td class="px-6 py-3.5">
                                        @if($severity === 'critical')
                                            <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">CRITICAL</span>
                                        @else
                                            <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">LOW</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="check-badge" class="mb-4 size-12 text-emerald-400 dark:text-emerald-500/70" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">Stock levels are healthy</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">No products are at or below their minimum stock for the current filters.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['total']) }} products)</td>
                                    <td class="px-6 py-4">Critical: {{ number_format($summary['critical']) }}</td>
                                    <td class="px-6 py-4">Low: {{ number_format($summary['low']) }}</td>
                                    <td class="px-6 py-4 text-right">Out of stock: {{ number_format($summary['out_of_stock']) }}</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['units_required']) }}</td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            </tfoot>
                        @endif
                    @elseif($reportType === 'dead_stock')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">SKU / Product</th>
                                <th scope="col" class="px-6 py-4">Category</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4 text-right">Current Stock</th>
                                <th scope="col" class="px-6 py-4 text-right">Stock Value</th>
                                <th scope="col" class="px-6 py-4">Last Transaction</th>
                                <th scope="col" class="px-6 py-4 text-right">Days Since Movement</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $product)
                                @php($days = $reportService->daysSinceLastMovement($product))
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-6 py-3.5">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</flux:text>
                                        <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $product->sku }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $product->category->name ?? 'Uncategorized' }}</td>
                                    <td class="px-6 py-3.5">{{ $product->supplier->name ?? 'Unassigned' }}</td>
                                    <td class="px-6 py-3.5 text-right font-semibold">{{ number_format($product->current_stock) }}</td>
                                    <td class="px-6 py-3.5 text-right">${{ number_format((float) $product->current_stock * (float) $product->cost_price, 2) }}</td>
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        @if($product->last_transaction_date)
                                            {{ \Illuminate\Support\Carbon::parse($product->last_transaction_date)->format('Y-m-d H:i') }}
                                        @else
                                            <span class="text-zinc-400">Never</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5 text-right">
                                        @if($days === null)
                                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Never moved</span>
                                        @else
                                            <span class="font-semibold {{ $days >= 90 ? 'text-rose-600 dark:text-rose-400' : ($days >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-700 dark:text-zinc-300') }}">{{ number_format($days) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="arrow-path-rounded-square" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">Everything is moving</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">No products have been idle for {{ $inactivityDays }} days under the current filters.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['total']) }} products)</td>
                                    <td class="px-6 py-4">Never moved: {{ number_format($summary['never_moved']) }}</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['total_units']) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['tied_value'], 2) }}</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            </tfoot>
                        @endif
                    @elseif($reportType === 'transactions')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">Date &amp; Time</th>
                                <th scope="col" class="px-6 py-4">SKU / Product</th>
                                <th scope="col" class="px-6 py-4">Category</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4">Type</th>
                                <th scope="col" class="px-6 py-4 text-right">Quantity</th>
                                <th scope="col" class="px-6 py-4">Operator</th>
                                <th scope="col" class="px-6 py-4">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $transaction)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        {{ $transaction->transaction_date->format('Y-m-d H:i') }}
                                        <flux:text class="block text-[10px] text-zinc-400">{{ $transaction->transaction_date->diffForHumans() }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $transaction->product->name ?? 'Deleted Product' }}</flux:text>
                                        <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $transaction->product->sku ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $transaction->product->category->name ?? 'Uncategorized' }}</td>
                                    <td class="px-6 py-3.5">{{ $transaction->product->supplier->name ?? 'Unassigned' }}</td>
                                    <td class="px-6 py-3.5">
                                        @if($transaction->type === 'stock_in')
                                            <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">STOCK IN</span>
                                        @elseif($transaction->type === 'stock_out')
                                            <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">STOCK OUT</span>
                                        @else
                                            <span class="rounded bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:bg-sky-950/30 dark:text-sky-400">ADJUSTMENT</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5 text-right font-semibold">
                                        @if($transaction->type === 'stock_in')
                                            +{{ number_format(abs($transaction->quantity)) }}
                                        @elseif($transaction->type === 'stock_out')
                                            -{{ number_format(abs($transaction->quantity)) }}
                                        @else
                                            {{ $transaction->quantity > 0 ? '+' : '' }}{{ number_format($transaction->quantity) }}
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5">{{ $transaction->user->name ?? 'System' }}</td>
                                    <td class="px-6 py-3.5 max-w-xs truncate" title="{{ $transaction->remarks }}">{{ $transaction->remarks ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="arrows-right-left" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No movement recorded</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">No inventory transactions match the selected date range and filters.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['transaction_count']) }})</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4">In: +{{ number_format($summary['stock_in']) }}</td>
                                    <td class="px-6 py-4">Out: -{{ number_format($summary['stock_out']) }}</td>
                                    <td class="px-6 py-4">Adj: {{ $summary['adjustments'] > 0 ? '+' : '' }}{{ number_format($summary['adjustments']) }}</td>
                                    <td class="px-6 py-4 text-right {{ $summary['net_movement'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                        {{ $summary['net_movement'] > 0 ? '+' : '' }}{{ number_format($summary['net_movement']) }}
                                    </td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            </tfoot>
                        @endif
                    @elseif($reportType === 'purchase_orders')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">PO Number</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4">Order Date</th>
                                <th scope="col" class="px-6 py-4">Expected Delivery</th>
                                <th scope="col" class="px-6 py-4">Status</th>
                                <th scope="col" class="px-6 py-4 text-right">Ordered</th>
                                <th scope="col" class="px-6 py-4 text-right">Received</th>
                                <th scope="col" class="px-6 py-4 text-right">Order Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $order)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="po-row-{{ $order->id }}">
                                    <td class="px-6 py-3.5">
                                        <a href="{{ route('purchase-orders.show', $order) }}" wire:navigate class="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ $order->po_number }}</a>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $order->supplier->name ?? 'Unassigned' }}</td>
                                    <td class="px-6 py-3.5 whitespace-nowrap">{{ $order->order_date->format('Y-m-d') }}</td>
                                    <td class="px-6 py-3.5 whitespace-nowrap {{ $order->isOverdue() ? 'font-semibold text-rose-600 dark:text-rose-400' : '' }}">
                                        {{ $order->expected_delivery_date?->format('Y-m-d') ?? 'Not set' }}
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <flux:badge :color="$order->statusColor()" size="sm" class="whitespace-nowrap">{{ $order->statusLabel() }}</flux:badge>
                                    </td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $order->units_ordered) }}</td>
                                    <td class="px-6 py-3.5 text-right font-semibold">{{ number_format((int) $order->units_received) }}</td>
                                    <td class="px-6 py-3.5 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $order->total_amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="clipboard-document-list" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No purchase orders</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Raise a purchase order or relax the filters to see procurement activity.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['order_count']) }} orders)</td>
                                    <td class="px-6 py-4">Open: {{ number_format($summary['open_orders']) }}</td>
                                    <td class="px-6 py-4">Received: {{ number_format($summary['received_orders']) }}</td>
                                    <td class="px-6 py-4">Cancelled: {{ number_format($summary['cancelled_orders']) }}</td>
                                    <td class="px-6 py-4">Overdue: {{ number_format($summary['overdue_orders']) }}</td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['units_ordered']) }}</td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['units_received']) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['total_value'], 2) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    @elseif($reportType === 'outstanding_orders')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">PO Number</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4">Expected Delivery</th>
                                <th scope="col" class="px-6 py-4">Status</th>
                                <th scope="col" class="px-6 py-4 text-right">Ordered</th>
                                <th scope="col" class="px-6 py-4 text-right">Outstanding</th>
                                <th scope="col" class="px-6 py-4 text-right">Outstanding Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $order)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="outstanding-row-{{ $order->id }}">
                                    <td class="px-6 py-3.5">
                                        <a href="{{ route('purchase-orders.show', $order) }}" wire:navigate class="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ $order->po_number }}</a>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $order->supplier->name ?? 'Unassigned' }}</td>
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        {{ $order->expected_delivery_date?->format('Y-m-d') ?? 'Not set' }}
                                        @if($order->isOverdue())
                                            <span class="ml-1 rounded bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">OVERDUE</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <flux:badge :color="$order->statusColor()" size="sm" class="whitespace-nowrap">{{ $order->statusLabel() }}</flux:badge>
                                    </td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $order->units_ordered) }}</td>
                                    <td class="px-6 py-3.5 text-right font-bold text-amber-600 dark:text-amber-400">{{ number_format(max((int) $order->units_ordered - (int) $order->units_received, 0)) }}</td>
                                    <td class="px-6 py-3.5 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $order->outstanding_value, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="check-badge" class="mb-4 size-12 text-emerald-400 dark:text-emerald-500/70" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">Nothing outstanding</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Every purchase order in scope has been fully received.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['order_count']) }} orders)</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4">Due in 7 days: {{ number_format($summary['due_within_week']) }}</td>
                                    <td class="px-6 py-4">Overdue: {{ number_format($summary['overdue_orders']) }}</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['units_outstanding']) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['outstanding_value'], 2) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    @elseif($reportType === 'supplier_purchases')
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4">Contact</th>
                                <th scope="col" class="px-6 py-4 text-right">Orders</th>
                                <th scope="col" class="px-6 py-4 text-right">Received Orders</th>
                                <th scope="col" class="px-6 py-4 text-right">Units Ordered</th>
                                <th scope="col" class="px-6 py-4 text-right">Units Received</th>
                                <th scope="col" class="px-6 py-4 text-right">Purchase Value</th>
                                <th scope="col" class="px-6 py-4">Last Order</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $supplier)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="supplier-purchase-{{ $supplier->id }}">
                                    <td class="px-6 py-3.5">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $supplier->name }}</flux:text>
                                        <flux:text class="block text-[11px] text-zinc-400">{{ $supplier->email ?: 'No email' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $supplier->contact_person ?: '-' }}</td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $supplier->order_count) }}</td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $supplier->received_orders) }}</td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $supplier->units_ordered) }}</td>
                                    <td class="px-6 py-3.5 text-right font-semibold">{{ number_format((int) $supplier->units_received) }}</td>
                                    <td class="px-6 py-3.5 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $supplier->total_value, 2) }}</td>
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        {{ $supplier->last_order_date ? \Illuminate\Support\Carbon::parse($supplier->last_order_date)->format('Y-m-d') : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="truck" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No purchase history</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">No supplier has purchase orders inside the selected filters.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['supplier_count']) }} suppliers)</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['order_count']) }}</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['units_ordered']) }}</td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['units_received']) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['total_value'], 2) }}</td>
                                    <td class="px-6 py-4"></td>
                                </tr>
                            </tfoot>
                        @endif
                    @else
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                <th scope="col" class="px-6 py-4">#</th>
                                <th scope="col" class="px-6 py-4">Supplier</th>
                                <th scope="col" class="px-6 py-4">Contact</th>
                                <th scope="col" class="px-6 py-4">Status</th>
                                <th scope="col" class="px-6 py-4 text-right">Products</th>
                                <th scope="col" class="px-6 py-4 text-right">Total Stock</th>
                                <th scope="col" class="px-6 py-4 text-right">Low Stock</th>
                                <th scope="col" class="px-6 py-4 text-right">Inventory Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($rows as $index => $supplier)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="px-6 py-3.5">
                                        <span class="flex size-6 items-center justify-center rounded-full bg-zinc-100 text-[11px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $rows->firstItem() + $index }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $supplier->name }}</flux:text>
                                        <flux:text class="block text-[11px] text-zinc-400">{{ $supplier->email ?: 'No email' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-3.5">{{ $supplier->contact_person ?: '-' }}</td>
                                    <td class="px-6 py-3.5">
                                        @if($supplier->status === 'active')
                                            <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">Active</span>
                                        @else
                                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ ucfirst($supplier->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $supplier->products_supplied) }}</td>
                                    <td class="px-6 py-3.5 text-right">{{ number_format((int) $supplier->total_units) }}</td>
                                    <td class="px-6 py-3.5 text-right {{ (int) $supplier->low_stock_products > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : '' }}">{{ number_format((int) $supplier->low_stock_products) }}</td>
                                    <td class="px-6 py-3.5 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $supplier->total_value, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-16">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="truck" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                            <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No suppliers to rank</flux:heading>
                                            <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Add suppliers or clear the filters to compare supplier contribution.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($rows->isNotEmpty())
                            <tfoot>
                                <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4">TOTALS ({{ number_format($summary['supplier_count']) }} suppliers)</td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4"></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['product_count']) }}</td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['total_units']) }}</td>
                                    <td class="px-6 py-4 text-right">{{ number_format($summary['low_stock_products']) }}</td>
                                    <td class="px-6 py-4 text-right">${{ number_format($summary['total_value'], 2) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    @endif
                </table>
            </div>

            @if($rows->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $rows->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
