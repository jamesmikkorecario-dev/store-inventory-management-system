<?php

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Services\InventoryService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;

new #[Title('Inventory Transactions')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterType = '';
    public string $filterStartDate = '';
    public string $filterEndDate = '';
    public string $filterProduct = '';

    // Form fields
    public ?int $productId = null;
    public string $type = 'stock_in'; // stock_in, stock_out, adjustment
    public int $quantity = 1;
    public string $adjustmentDirection = 'add'; // add, subtract (for adjustment type)
    public string $remarks = '';

    public bool $showFormModal = false;

    // Permissions
    #[Locked]
    public bool $isSupplier = false;

    #[Locked]
    public ?int $userSupplierId = null;

    #[Locked]
    public bool $canManage = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->isSupplier = $user->hasRole('Supplier');
        $this->userSupplierId = $user->supplier_id;

        if ($this->isSupplier) {
            $this->canManage = false;
        } else {
            $this->canManage = $user->can('manage inventory');
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[On('transactions-updated')]
    #[On('products-updated')]
    public function refreshData(): void
    {
        // Component will re-render
    }

    public function updatedFilterType(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStartDate(): void
    {
        $this->resetPage();
    }

    public function updatedFilterEndDate(): void
    {
        $this->resetPage();
    }

    public function updatedFilterProduct(): void
    {
        $this->resetPage();
    }


    public function openLogModal(): void
    {
        if (!$this->canManage) abort(403);
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function saveTransaction(InventoryService $inventoryService): void
    {
        if (!$this->canManage) abort(403);

        $rules = [
            'productId' => 'required|exists:products,id',
            'type' => 'required|in:stock_in,stock_out,adjustment',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:500',
        ];

        if ($this->type === 'adjustment') {
            $rules['adjustmentDirection'] = 'required|in:add,subtract';
        }

        $messages = [
            'productId.required' => 'Product is required.',
            'productId.exists' => 'Selected product is invalid.',
            'type.required' => 'Movement direction is required.',
            'quantity.required' => 'Quantity is required.',
            'quantity.integer' => 'Quantity must be an integer.',
            'quantity.min' => 'Quantity must be at least 1.',
            'adjustmentDirection.required' => 'Adjustment type is required.',
        ];

        $this->validate($rules, $messages);

        // Convert quantity for adjustment direction
        $finalQuantity = $this->quantity;
        if ($this->type === 'adjustment' && $this->adjustmentDirection === 'subtract') {
            $finalQuantity = -$this->quantity;
        }

        try {
            $inventoryService->logTransaction(
                $this->productId,
                Auth::id(),
                $this->type,
                $finalQuantity,
                $this->remarks
            );

            $this->dispatch('transactions-updated');
            $this->dispatch('products-updated');
            $this->dispatch('inventory-updated');
            $this->dispatch('alerts-updated');
            $this->dispatch('dashboard-updated');

            Flux::toast(variant: 'success', text: 'Created successfully.');
            $this->showFormModal = false;
            $this->resetForm();
        } catch (Exception $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    private function resetForm(): void
    {
        $this->productId = null;
        $this->type = 'stock_in';
        $this->quantity = 1;
        $this->adjustmentDirection = 'add';
        $this->remarks = '';
    }

    public function with(): array
    {
        $query = InventoryTransaction::with(['product', 'user']);

        // Supplier boundaries: Can only view 'stock_in' (deliveries) of their assigned products
        if ($this->isSupplier) {
            $productIds = Product::where('supplier_id', $this->userSupplierId)->pluck('id')->toArray();
            $query->whereIn('product_id', $productIds)
                  ->where('type', 'stock_in');
        }

        if ($this->search) {
            $query->whereHas('product', function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterProduct) {
            $query->where('product_id', $this->filterProduct);
        }

        if ($this->filterType && !$this->isSupplier) {
            $query->where('type', $this->filterType);
        }

        if ($this->filterStartDate) {
            $query->whereDate('transaction_date', '>=', $this->filterStartDate);
        }

        if ($this->filterEndDate) {
            $query->whereDate('transaction_date', '<=', $this->filterEndDate);
        }

        $productsQuery = Product::query()
            ->where('status', 'active')
            ->select('id', 'name', 'sku', 'current_stock');
            
        if ($this->isSupplier) {
            $productsQuery->where('supplier_id', $this->userSupplierId);
        }
        
        $formProducts = $productsQuery->orderBy('name')->get();

        return [
            'transactions' => $query->latest('transaction_date')->paginate(15),
            'formProducts' => $formProducts,
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">Inventory Transactions</flux:heading>
                <flux:subheading>
                    {{ $isSupplier ? 'Track completed deliveries and intake schedules.' : 'Log stock intakes, inventory dispatches, and warehouse count adjustments.' }}
                </flux:subheading>
            </div>
            @if($canManage)
                <flux:button wire:click="openLogModal" variant="primary" icon="arrows-right-left">Log Stock Movement</flux:button>
            @endif
        </div>

        <!-- Filters -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 items-end bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="lg:col-span-2 relative">
                <flux:input wire:model.live.debounce.300ms="search" label="Search Product" placeholder="Search product name or SKU..." icon="magnifying-glass" />
                <div wire:loading wire:target="search" class="absolute right-3 top-9">
                    <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
                </div>
            </div>
            @if(!$isSupplier)
                <div>
                    <flux:select wire:model.live="filterType" label="Transaction Type">
                        <option value="">All Types</option>
                        <option value="stock_in">Stock In</option>
                        <option value="stock_out">Stock Out</option>
                        <option value="adjustment">Adjustment</option>
                    </flux:select>
                </div>
            @endif
            <div>
                <flux:input wire:model.live="filterStartDate" type="date" label="Start Date" />
            </div>
            <div>
                <flux:input wire:model.live="filterEndDate" type="date" label="End Date" />
            </div>
        </div>

        <!-- Transactions List -->
        <div wire:loading wire:target="search, filterType, filterStartDate, filterEndDate, filterProduct, sortBy, gotoPage, nextPage, previousPage" class="flex justify-center py-4 w-full">
            <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
        </div>
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:loading.class="opacity-50 pointer-events-none" wire:target="search, filterType, filterStartDate, filterEndDate, filterProduct, sortBy, gotoPage, nextPage, previousPage">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-6 py-4" style="width: 18%;">Transaction Date</th>
                            <th scope="col" class="px-6 py-4" style="width: 25%;">Product details</th>
                            <th scope="col" class="px-6 py-4" style="width: 12%;">Type</th>
                            <th scope="col" class="px-6 py-4" style="width: 10%;">Quantity</th>
                            @if(!$isSupplier)
                                <th scope="col" class="px-6 py-4" style="width: 15%;">Unit Cost / Price</th>
                            @endif
                            <th scope="col" class="px-6 py-4" style="width: 12%;">Operator User</th>
                            <th scope="col" class="px-6 py-4" style="width: 8%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($transactions as $tx)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 font-medium whitespace-nowrap">
                                    {{ $tx->transaction_date->format('M d, Y h:i A') }}
                                    <flux:text class="block text-[10px] text-zinc-400">{{ $tx->transaction_date->diffForHumans() }}</flux:text>
                                </td>
                                <td class="px-6 py-4">
                                    <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $tx->product->name ?? 'Deleted Product' }}</flux:text>
                                    <span class="rounded bg-zinc-150 px-2 py-0.5 text-[9px] font-mono font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">{{ $tx->product->sku ?? '' }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    @if($tx->type === 'stock_in')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400 whitespace-nowrap">
                                            Stock In
                                        </span>
                                    @elseif($tx->type === 'stock_out')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400 whitespace-nowrap">
                                            Stock Out
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400 whitespace-nowrap">
                                            Adjustment
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 font-bold text-sm {{ $tx->type === 'stock_in' ? 'text-emerald-600' : ($tx->type === 'stock_out' ? 'text-rose-600' : 'text-zinc-700 dark:text-zinc-300') }}">
                                    {{ $tx->type === 'stock_in' ? '+' : ($tx->type === 'stock_out' ? '-' : ($tx->quantity >= 0 ? '+' : '')) }}{{ abs($tx->quantity) }}
                                </td>
                                @if(!$isSupplier)
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 font-medium">
                                        <div class="flex flex-col space-y-0.5 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5 text-xs">
                                                <span class="text-[10px] uppercase font-bold text-zinc-400 tracking-wider w-8">Cost:</span>
                                                <span>${{ number_format($tx->unit_cost ?: 0, 2) }}</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 text-xs">
                                                <span class="text-[10px] uppercase font-bold text-zinc-400 tracking-wider w-8">Sell:</span>
                                                <span class="text-zinc-900 dark:text-white font-semibold">${{ number_format($tx->unit_price ?: 0, 2) }}</span>
                                            </div>
                                        </div>
                                    </td>
                                @endif
                                <td class="px-6 py-4 text-zinc-500">
                                    {{ $tx->user->name ?? 'Deleted System User' }}
                                </td>
                                <td class="px-6 py-4 text-zinc-500 max-w-[250px] truncate" title="{{ $tx->remarks }}">
                                    {{ $tx->remarks ?: '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-16">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <flux:icon name="arrows-right-left" class="size-12 text-zinc-300 dark:text-zinc-600 mb-4" />
                                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No Transactions Yet</flux:heading>
                                        <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">Record your first stock movement to begin tracking.</flux:text>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($transactions->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $transactions->links() }}
                </div>
            @endif
        </div>

        <!-- Log Stock Modal -->
        @if($showFormModal)
            <flux:modal wire:model="showFormModal" class="w-full max-w-lg">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">Log Stock Movement</flux:heading>
                        <flux:subheading>Record physical inventory changes inside the warehouse. All details are immutable once logged.</flux:subheading>
                    </div>

                    <form wire:submit.prevent="saveTransaction" class="space-y-0" novalidate>
                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Select Product <span class="text-rose-500">*</span></flux:label>
                            <!-- Alpine Combobox -->
                            <div x-data="{
                                open: false,
                                search: '',
                                selectedId: @entangle('productId'),
                                selectedName: '',
                                highlightedIndex: 0,
                                products: {{ Js::from($formProducts) }},
                                get filteredProducts() {
                                    if (this.search === '') {
                                        return this.products.slice(0, 50);
                                    }
                                    const lowerSearch = this.search.toLowerCase();
                                    return this.products.filter(p => 
                                        p.name.toLowerCase().includes(lowerSearch) || 
                                        p.sku.toLowerCase().includes(lowerSearch)
                                    ).slice(0, 50);
                                },
                                init() {
                                    this.$watch('selectedId', (val) => {
                                        if (val) {
                                            const p = this.products.find(x => x.id === val);
                                            if (p) this.selectedName = p.name;
                                        } else {
                                            this.selectedName = '';
                                        }
                                    });
                                    if (this.selectedId) {
                                        const p = this.products.find(x => x.id === this.selectedId);
                                        if (p) this.selectedName = p.name;
                                    }
                                },
                                openDropdown() {
                                    this.open = true;
                                    this.search = '';
                                    this.highlightedIndex = 0;
                                    this.$nextTick(() => { this.$refs.searchInput.focus(); });
                                },
                                closeDropdown() {
                                    this.open = false;
                                },
                                selectProduct(product) {
                                    this.selectedId = product.id;
                                    this.selectedName = product.name;
                                    this.closeDropdown();
                                },
                                highlightNext() {
                                    if (this.highlightedIndex < this.filteredProducts.length - 1) {
                                        this.highlightedIndex++;
                                        this.scrollToHighlighted();
                                    }
                                },
                                highlightPrevious() {
                                    if (this.highlightedIndex > 0) {
                                        this.highlightedIndex--;
                                        this.scrollToHighlighted();
                                    }
                                },
                                selectHighlighted() {
                                    if (this.open && this.filteredProducts.length > 0) {
                                        this.selectProduct(this.filteredProducts[this.highlightedIndex]);
                                    }
                                },
                                scrollToHighlighted() {
                                    this.$nextTick(() => {
                                        const el = this.$refs.dropdownList.children[this.highlightedIndex];
                                        if (el) {
                                            el.scrollIntoView({ block: 'nearest' });
                                        }
                                    });
                                }
                            }"
                            @keydown.escape.window="closeDropdown()"
                            @click.outside="closeDropdown()"
                            class="relative w-full"
                            >
                                <!-- Trigger Button / Input -->
                                <button type="button" @click="openDropdown()" class="flex items-center justify-between w-full h-10 px-3 py-2 text-sm leading-5 bg-white border rounded-lg shadow-sm appearance-none border-zinc-200 border-b-zinc-300/80 text-zinc-700 dark:bg-white/10 dark:border-white/10 dark:text-zinc-300 focus:outline-hidden focus:ring-2 focus:ring-primary-500">
                                    <span x-text="selectedName ? selectedName : 'Choose a product...'" :class="selectedName ? '' : 'text-zinc-400 dark:text-zinc-400'" class="truncate"></span>
                                    <flux:icon name="chevron-down" class="w-4 h-4 text-zinc-400" />
                                </button>
                                
                                <!-- Dropdown Menu -->
                                <div x-show="open" x-cloak
                                     class="absolute left-0 z-50 w-full mt-1 bg-white border rounded-lg shadow-lg border-zinc-200 dark:bg-zinc-800 dark:border-zinc-700 overflow-hidden"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                >
                                    <!-- Search Input -->
                                    <div class="p-2 border-b border-zinc-100 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                                        <div class="relative flex items-center">
                                            <flux:icon name="magnifying-glass" class="absolute w-4 h-4 left-3 text-zinc-400" />
                                            <input type="text" x-ref="searchInput" x-model="search"
                                                @keydown.arrow-down.prevent="highlightNext()"
                                                @keydown.arrow-up.prevent="highlightPrevious()"
                                                @keydown.enter.prevent="selectHighlighted()"
                                                placeholder="Search by name or SKU..."
                                                class="w-full h-9 pl-9 pr-3 text-sm bg-white border rounded-md border-zinc-200 text-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-primary-500 focus:border-primary-500 dark:bg-zinc-900 dark:border-zinc-700 dark:text-white dark:focus:ring-primary-500"
                                            />
                                        </div>
                                    </div>
                                    
                                    <!-- Results List -->
                                    <ul x-ref="dropdownList" class="overflow-y-auto max-h-[250px] p-1">
                                        <template x-for="(product, index) in filteredProducts" :key="product.id">
                                            <li @click="selectProduct(product)"
                                                @mouseenter="highlightedIndex = index"
                                                :class="{'bg-zinc-100 dark:bg-zinc-700': highlightedIndex === index}"
                                                class="flex flex-col px-3 py-2 cursor-pointer rounded-md select-none"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="product.name"></span>
                                                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400" x-text="'Stock: ' + product.current_stock"></span>
                                                </div>
                                                <div class="text-xs text-zinc-500 dark:text-zinc-400" x-text="'SKU: ' + product.sku"></div>
                                            </li>
                                        </template>
                                        <li x-show="filteredProducts.length === 0" class="px-3 py-4 text-sm text-center text-zinc-500 dark:text-zinc-400">
                                            No products found.
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <flux:error name="productId" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Movement Direction <span class="text-rose-500">*</span></flux:label>
                            <flux:select wire:model.live="type" required>
                                <option value="stock_in">Stock In (Receive Goods)</option>
                                <option value="stock_out">Stock Out (Dispatch Goods)</option>
                                <option value="adjustment">Stock Adjustment (Audit Discrepancies)</option>
                            </flux:select>
                            <flux:error name="type" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <div class="grid grid-cols-2 gap-x-4">
                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Quantity <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="quantity" type="number" min="1" required />
                                <flux:error name="quantity" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>

                            @if($type === 'adjustment')
                                <flux:field class="mb-4">
                                    <flux:label class="mb-1">Adjustment Type <span class="text-rose-500">*</span></flux:label>
                                    <flux:select wire:model="adjustmentDirection" required>
                                        <option value="add">Add Stock (+)</option>
                                        <option value="subtract">Deduct Stock (-)</option>
                                    </flux:select>
                                    <flux:error name="adjustmentDirection" class="!mt-0.5 text-xs font-medium" />
                                </flux:field>
                            @endif
                        </div>

                        <flux:field class="mb-5">
                            <flux:label class="mb-1">Remarks</flux:label>
                            <flux:textarea wire:model="remarks" placeholder="E.g., PO-9801 receipt, client refund, damaged box write-off..." rows="3" />
                            <flux:error name="remarks" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <div class="flex justify-end gap-3">
                            <flux:button wire:click="$set('showFormModal', false)" variant="ghost">Cancel</flux:button>
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="saveTransaction">Record Movement</span>
                                <span wire:loading wire:target="saveTransaction" class="flex items-center gap-2">
                                    <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                    Saving...
                                </span>
                            </flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>
        @endif
    </div>
