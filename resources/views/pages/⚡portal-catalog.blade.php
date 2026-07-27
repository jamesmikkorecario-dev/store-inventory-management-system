<?php

use App\Models\Category;
use App\Models\Supplier;
use App\Services\SupplierPortalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My Product Catalog')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterStatus = '';
    public string $filterCategory = '';

    #[Locked]
    public ?Supplier $supplier = null;

    public function mount(): void
    {
        // Route middleware restricts this page to the Supplier role; the linked
        // supplier below is what scopes every query on the page.
        $this->supplier = Auth::user()?->supplier;
    }

    public function updated(string $property, mixed $value = null): void
    {
        if (in_array($property, ['search', 'filterStatus', 'filterCategory'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterCategory']);
        $this->resetPage();
    }

    public function with(): array
    {
        if ($this->supplier === null) {
            return [
                'products' => null,
                'categories' => collect(),
                'summary' => ['total' => 0, 'units' => 0, 'value' => 0.0, 'low_stock' => 0],
            ];
        }

        $portal = app(SupplierPortalService::class);
        $metrics = $portal->dashboardMetrics($this->supplier);

        return [
            'products' => $portal->productQuery($this->supplier, [
                'search' => $this->search,
                'status' => $this->filterStatus,
                'categoryId' => $this->filterCategory,
            ])->paginate(15),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'summary' => [
                'total' => $metrics['total_products'],
                'units' => $metrics['total_units'],
                'value' => $metrics['inventory_value'],
                'low_stock' => $metrics['low_stock_products'],
            ],
            'hasFilters' => $this->search !== '' || $this->filterStatus !== '' || $this->filterCategory !== '',
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">My Product Catalog</flux:heading>
            <flux:subheading>Read-only view of the products you supply and their current stock position.</flux:subheading>
        </div>
        <flux:badge color="zinc" size="sm" icon="lock-closed">Read only</flux:badge>
    </div>

    @if($supplier === null)
        <x-portal-no-supplier />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-report-stat-card label="Products Supplied" icon="archive-box" icon-class="text-indigo-500" :value="number_format($summary['total'])" hint="In your catalog" />
            <x-report-stat-card label="Units On Hand" icon="cube" icon-class="text-sky-500" :value="number_format($summary['units'])" hint="Across all products" />
            <x-report-stat-card label="Inventory Value" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format($summary['value'], 2)" hint="At your cost price" />
            <x-report-stat-card
                label="Low Stock"
                icon="exclamation-triangle"
                :icon-class="$summary['low_stock'] > 0 ? 'text-amber-500' : 'text-zinc-400'"
                :value="number_format($summary['low_stock'])"
                :hint="$summary['low_stock'] > 0 ? 'May need replenishment' : 'All stocked up'"
                :hint-class="$summary['low_stock'] > 0 ? 'text-amber-500' : 'text-zinc-500'"
            />
        </div>

        <!-- Filters -->
        <div class="grid grid-cols-1 items-end gap-4 rounded-xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="relative sm:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Product name or SKU..." icon="magnifying-glass" />
                <div wire:loading wire:target="search" class="absolute right-3 top-9">
                    <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
                </div>
            </div>
            <div>
                <flux:select wire:model.live="filterCategory" label="Category">
                    <option value="">All Categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="filterStatus" label="Status">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="discontinued">Discontinued</option>
                </flux:select>
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <flux:button wire:click="resetFilters" icon="arrow-uturn-left" size="sm" variant="{{ $hasFilters ? 'filled' : 'ghost' }}" data-test="reset-filters">Reset Filters</flux:button>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] table-fixed text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-4 py-4 w-[24%]">SKU / Product</th>
                            <th scope="col" class="px-4 py-4 w-[16%]">Category</th>
                            <th scope="col" class="px-4 py-4 w-[15%]">Stock Level</th>
                            <th scope="col" class="px-4 py-4 w-[12%]">Minimum</th>
                            <th scope="col" class="px-4 py-4 w-[13%]">Cost Price</th>
                            <th scope="col" class="px-4 py-4 w-[12%]">Stock Value</th>
                            <th scope="col" class="px-4 py-4 w-[8%] text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($products as $product)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="cat-{{ $product->id }}">
                                <td class="px-4 py-3.5">
                                    <flux:text class="block truncate font-semibold text-zinc-900 dark:text-white" title="{{ $product->name }}">{{ $product->name }}</flux:text>
                                    <flux:text class="block truncate font-mono text-[11px] text-zinc-400">{{ $product->sku }}</flux:text>
                                </td>
                                <td class="px-4 py-3.5">
                                    <flux:text class="block truncate" title="{{ $product->category->name ?? 'Uncategorized' }}">{{ $product->category->name ?? 'Uncategorized' }}</flux:text>
                                </td>
                                <td class="px-4 py-3.5">
                                    @if($product->current_stock <= 0)
                                        <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">Out of stock</span>
                                    @elseif($product->isLowStock())
                                        <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">Low ({{ number_format($product->current_stock) }})</span>
                                    @else
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">{{ number_format($product->current_stock) }} in stock</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">{{ number_format($product->minimum_stock) }}</td>
                                <td class="px-4 py-3.5 whitespace-nowrap">${{ number_format((float) $product->cost_price, 2) }}</td>
                                <td class="px-4 py-3.5 whitespace-nowrap font-medium text-zinc-900 dark:text-white">
                                    ${{ number_format($product->current_stock * (float) $product->cost_price, 2) }}
                                </td>
                                <td class="px-4 py-3.5 text-center">
                                    <flux:badge :color="$product->status === 'active' ? 'emerald' : 'zinc'" size="sm">{{ ucfirst($product->status) }}</flux:badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-16">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <flux:icon name="archive-box" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No products found</flux:heading>
                                        <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ $hasFilters ? 'No product matches the current filters.' : 'No products are assigned to your account yet.' }}
                                        </flux:text>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($products->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $products->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
