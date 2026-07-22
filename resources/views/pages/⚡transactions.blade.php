<?php

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Services\InventoryService;
use Livewire\Attributes\Title;
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
    public bool $isSupplier = false;
    public ?int $userSupplierId = null;
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

        $this->validate($rules);

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

            Flux::toast(variant: 'success', text: __('Transaction processed successfully. Stock updated.'));
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

        return [
            'transactions' => $query->latest('transaction_date')->paginate(15),
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex items-center justify-between">
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
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="lg:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Search product name or SKU..." icon="magnifying-glass" />
            </div>
            @if(!$isSupplier)
                <div>
                    <flux:select wire:model.live="filterType">
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
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th class="px-6 py-4">Transaction Date</th>
                            <th class="px-6 py-4">Product details</th>
                            <th class="px-6 py-4">Type</th>
                            <th class="px-6 py-4">Quantity</th>
                            @if(!$isSupplier)
                                <th class="px-6 py-4">Unit Cost / Price</th>
                            @endif
                            <th class="px-6 py-4">Operator User</th>
                            <th class="px-6 py-4">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($transactions as $tx)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 font-medium">
                                    {{ $tx->transaction_date->format('M d, Y h:i A') }}
                                    <flux:text class="block text-[10px] text-zinc-400">{{ $tx->transaction_date->diffForHumans() }}</flux:text>
                                </td>
                                <td class="px-6 py-4">
                                    <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $tx->product->name ?? 'Deleted Product' }}</flux:text>
                                    <span class="rounded bg-zinc-150 px-2 py-0.5 text-[9px] font-mono font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">{{ $tx->product->sku ?? '' }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    @if($tx->type === 'stock_in')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">
                                            Stock In
                                        </span>
                                    @elseif($tx->type === 'stock_out')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">
                                            Stock Out
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                                            Adjustment
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 font-bold text-sm {{ $tx->type === 'stock_in' ? 'text-emerald-600' : ($tx->type === 'stock_out' ? 'text-rose-600' : 'text-zinc-700 dark:text-zinc-300') }}">
                                    {{ $tx->type === 'stock_in' ? '+' : ($tx->type === 'stock_out' ? '-' : ($tx->quantity >= 0 ? '+' : '')) }}{{ abs($tx->quantity) }}
                                </td>
                                @if(!$isSupplier)
                                    <td class="px-6 py-4 text-xs text-zinc-500">
                                        C: ${{ number_format($tx->unit_cost ?: 0, 2) }}
                                        <span class="mx-1 text-zinc-300">|</span>
                                        S: ${{ number_format($tx->unit_price ?: 0, 2) }}
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
                                <td colspan="7" class="px-6 py-8 text-center text-zinc-500">No transactions found.</td>
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
            <flux:modal wire:model="showFormModal" class="min-w-[500px]">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Log Stock Movement</flux:heading>
                        <flux:subheading>Record physical inventory changes inside the warehouse. All details are immutable once logged.</flux:subheading>
                    </div>

                    <form wire:submit.prevent="saveTransaction" class="space-y-4" novalidate>
                        <flux:select wire:model="productId" label="Select Product" required>
                            <option value="">Choose product...</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} (SKU: {{ $p->sku }}, Current Stock: {{ $p->current_stock }})</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="type" label="Movement Direction" required>
                            <option value="stock_in">Stock In (Receive Goods)</option>
                            <option value="stock_out">Stock Out (Dispatch Goods)</option>
                            <option value="adjustment">Stock Adjustment (Audit Discrepancies)</option>
                        </flux:select>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model="quantity" label="Quantity" type="number" min="1" required />

                            @if($type === 'adjustment')
                                <flux:select wire:model="adjustmentDirection" label="Adjustment Type" required>
                                    <option value="add">Add Stock (+)</option>
                                    <option value="subtract">Deduct Stock (-)</option>
                                </flux:select>
                            @endif
                        </div>

                        <flux:textarea wire:model="remarks" label="Remarks" required placeholder="E.g., PO-9801 receipt, client refund, damaged box write-off..." rows="3" />

                        <div class="flex justify-end gap-3 mt-6">
                            <flux:button wire:click="$set('showFormModal', false)" variant="ghost">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Record Movement</flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>
        @endif
    </div>
