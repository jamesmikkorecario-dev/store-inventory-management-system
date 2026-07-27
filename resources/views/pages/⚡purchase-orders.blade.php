<?php

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Purchase Orders')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterStatus = '';
    public string $filterSupplier = '';
    public string $filterStartDate = '';
    public string $filterEndDate = '';

    // Form state
    public bool $showFormModal = false;
    public ?int $editingId = null;
    public string $supplierId = '';
    public string $orderDate = '';
    public string $expectedDeliveryDate = '';
    public string $notes = '';

    /**
     * Editable line items: product_id, quantity_ordered and unit_cost per row.
     *
     * @var list<array{product_id: string, quantity_ordered: string, unit_cost: string}>
     */
    public array $lineItems = [];

    // Permissions
    public bool $canManage = false;
    public bool $canApprove = false;
    public bool $canReceive = false;

    public function mount(): void
    {
        $user = Auth::user();

        $this->canManage = (bool) $user?->can('manage purchase orders');
        $this->canApprove = (bool) $user?->can('approve purchase orders');
        $this->canReceive = (bool) $user?->can('receive purchase orders');
    }

    public function updated(string $property, mixed $value = null): void
    {
        if (in_array($property, ['search', 'filterStatus', 'filterSupplier', 'filterStartDate', 'filterEndDate'], true)) {
            $this->resetPage();
        }
    }

    #[On('purchase-orders-updated')]
    public function refreshData(): void
    {
        // Re-render with fresh data.
    }

    public function openCreateModal(): void
    {
        abort_unless($this->canManage, 403);

        $this->resetForm();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        abort_unless($this->canManage, 403);

        $order = PurchaseOrder::with('items')->findOrFail($id);

        if (! $order->isEditable()) {
            Flux::toast(variant: 'warning', text: 'Only draft purchase orders can be edited.');

            return;
        }

        $this->resetValidation();

        $this->editingId = $order->id;
        $this->supplierId = (string) $order->supplier_id;
        $this->orderDate = $order->order_date->toDateString();
        $this->expectedDeliveryDate = $order->expected_delivery_date?->toDateString() ?? '';
        $this->notes = $order->notes ?? '';
        $this->lineItems = $order->items
            ->map(fn ($item): array => [
                'product_id' => (string) $item->product_id,
                'quantity_ordered' => (string) $item->quantity_ordered,
                'unit_cost' => number_format((float) $item->unit_cost, 2, '.', ''),
            ])
            ->values()
            ->all();

        $this->showFormModal = true;
    }

    public function addLineItem(): void
    {
        $this->lineItems[] = ['product_id' => '', 'quantity_ordered' => '1', 'unit_cost' => '0.00'];
    }

    public function removeLineItem(int $index): void
    {
        unset($this->lineItems[$index]);

        $this->lineItems = array_values($this->lineItems);

        if ($this->lineItems === []) {
            $this->addLineItem();
        }
    }

    /**
     * Prefill the unit cost from the product's current cost price.
     */
    public function updatedLineItems(mixed $value, ?string $key = null): void
    {
        if ($key === null || ! str_ends_with($key, '.product_id') || $value === '' || $value === null) {
            return;
        }

        $index = (int) explode('.', $key)[0];
        $product = Product::find((int) $value);

        if ($product && (float) ($this->lineItems[$index]['unit_cost'] ?? 0) === 0.0) {
            $this->lineItems[$index]['unit_cost'] = number_format((float) $product->cost_price, 2, '.', '');
        }
    }

    public function save(PurchaseOrderService $service): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'supplierId' => 'required|exists:suppliers,id',
            'orderDate' => 'required|date',
            'expectedDeliveryDate' => 'nullable|date|after_or_equal:orderDate',
            'notes' => 'nullable|string|max:1000',
            'lineItems' => 'required|array|min:1',
            'lineItems.*.product_id' => 'required|exists:products,id',
            'lineItems.*.quantity_ordered' => 'required|integer|min:1',
            'lineItems.*.unit_cost' => 'required|numeric|min:0',
        ], [
            'supplierId.required' => 'Supplier is required.',
            'orderDate.required' => 'Order date is required.',
            'expectedDeliveryDate.after_or_equal' => 'Expected delivery cannot be before the order date.',
            'lineItems.required' => 'Add at least one line item.',
            'lineItems.*.product_id.required' => 'Select a product for every line.',
            'lineItems.*.quantity_ordered.min' => 'Quantity must be at least 1.',
            'lineItems.*.unit_cost.min' => 'Unit cost cannot be negative.',
        ]);

        $attributes = [
            'supplier_id' => $this->supplierId,
            'order_date' => $this->orderDate,
            'expected_delivery_date' => $this->expectedDeliveryDate,
            'notes' => $this->notes === '' ? null : $this->notes,
        ];

        try {
            if ($this->editingId !== null) {
                $service->update(PurchaseOrder::findOrFail($this->editingId), $attributes, $this->lineItems);
                $message = 'Purchase order updated successfully.';
            } else {
                $service->create($attributes, $this->lineItems, Auth::user());
                $message = 'Purchase order created successfully.';
            }
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->dispatch('purchase-orders-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(variant: 'success', text: $message);

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function submitOrder(int $id, PurchaseOrderService $service): void
    {
        abort_unless($this->canManage, 403);

        $this->runWorkflowAction(
            fn () => $service->submit(PurchaseOrder::findOrFail($id)),
            'Purchase order submitted for approval.',
        );
    }

    public function approveOrder(int $id, PurchaseOrderService $service): void
    {
        abort_unless($this->canApprove, 403);

        $this->runWorkflowAction(
            fn () => $service->approve(PurchaseOrder::findOrFail($id), Auth::user()),
            'Purchase order approved.',
        );
    }

    public function cancelOrder(int $id, PurchaseOrderService $service): void
    {
        abort_unless($this->canManage, 403);

        $this->runWorkflowAction(
            fn () => $service->cancel(PurchaseOrder::findOrFail($id)),
            'Purchase order cancelled.',
        );
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterSupplier', 'filterStartDate', 'filterEndDate']);
        $this->resetPage();
    }

    public function with(): array
    {
        $query = PurchaseOrder::query()
            ->with(['supplier', 'creator'])
            ->withCount('items')
            ->withSum('items as ordered_units', 'quantity_ordered')
            ->withSum('items as received_units', 'quantity_received');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($inner) use ($term) {
                $inner->where('po_number', 'like', $term)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $term));
            });
        }

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterSupplier !== '') {
            $query->where('supplier_id', $this->filterSupplier);
        }

        if ($this->filterStartDate !== '') {
            $query->whereDate('order_date', '>=', $this->filterStartDate);
        }

        if ($this->filterEndDate !== '') {
            $query->whereDate('order_date', '<=', $this->filterEndDate);
        }

        $metrics = app(PurchaseOrderService::class)->dashboardMetrics();

        $formProducts = Product::query()
            ->where('status', 'active')
            ->when($this->supplierId !== '', fn ($products) => $products->where('supplier_id', $this->supplierId))
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'cost_price', 'supplier_id']);

        return [
            'orders' => $query->latest('order_date')->latest('id')->paginate(15),
            'statuses' => PurchaseOrder::STATUSES,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'formProducts' => $formProducts,
            'metrics' => $metrics,
            'hasActiveFilters' => $this->search !== '' || $this->filterStatus !== '' || $this->filterSupplier !== '' || $this->filterStartDate !== '' || $this->filterEndDate !== '',
            'draftTotal' => collect($this->lineItems)->sum(fn (array $line): float => (float) ($line['quantity_ordered'] ?? 0) * (float) ($line['unit_cost'] ?? 0)),
        ];
    }

    private function runWorkflowAction(callable $action, string $message): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->dispatch('purchase-orders-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(variant: 'success', text: $message);
    }

    private function resetForm(): void
    {
        $this->resetValidation();

        $this->editingId = null;
        $this->supplierId = '';
        $this->orderDate = now()->toDateString();
        $this->expectedDeliveryDate = '';
        $this->notes = '';
        $this->lineItems = [['product_id' => '', 'quantity_ordered' => '1', 'unit_cost' => '0.00']];
    }
}; ?>

@php
    $loadingTargets = 'search, filterStatus, filterSupplier, filterStartDate, filterEndDate, resetFilters, gotoPage, nextPage, previousPage';
@endphp

<div class="space-y-6">
    <!-- Heading -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">Purchase Orders</flux:heading>
            <flux:subheading>Raise orders with suppliers, track approvals and book deliveries into stock.</flux:subheading>
        </div>
        @if($canManage)
            <flux:button wire:click="openCreateModal" variant="primary" icon="plus" data-test="new-purchase-order">New Purchase Order</flux:button>
        @endif
    </div>

    <!-- Metrics -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-report-stat-card
            label="Open Orders"
            icon="clipboard-document-list"
            icon-class="text-indigo-500"
            :value="number_format($metrics['open_orders'])"
            :hint="'$' . number_format($metrics['open_value'], 2) . ' committed'"
        />
        <x-report-stat-card
            label="Awaiting Approval"
            icon="clock"
            icon-class="text-sky-500"
            :value="number_format($metrics['awaiting_approval'])"
            hint="Submitted orders"
        />
        <x-report-stat-card
            label="Pending Deliveries"
            icon="truck"
            icon-class="text-amber-500"
            :value="number_format($metrics['pending_deliveries'])"
            hint="Approved, not fully received"
        />
        <x-report-stat-card
            label="Overdue Deliveries"
            icon="exclamation-triangle"
            :icon-class="$metrics['overdue_deliveries'] > 0 ? 'text-rose-500' : 'text-zinc-400'"
            :value="number_format($metrics['overdue_deliveries'])"
            :hint="$metrics['overdue_deliveries'] > 0 ? 'Past expected date' : 'On schedule'"
            :hint-class="$metrics['overdue_deliveries'] > 0 ? 'text-rose-500' : 'text-zinc-500'"
        />
    </div>

    <!-- Filters -->
    <div class="grid grid-cols-1 items-end gap-4 rounded-xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="relative sm:col-span-2">
            <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="PO number or supplier..." icon="magnifying-glass" />
            <div wire:loading wire:target="search" class="absolute right-3 top-9">
                <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
            </div>
        </div>

        <div>
            <flux:input wire:model.live="filterStartDate" type="date" label="Ordered From" />
        </div>
        <div>
            <flux:input wire:model.live="filterEndDate" type="date" label="Ordered To" />
        </div>

        <div>
            <flux:select wire:model.live="filterStatus" label="Status">
                <option value="">All Statuses</option>
                @foreach($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <flux:select wire:model.live="filterSupplier" label="Supplier">
                <option value="">All Suppliers</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </flux:select>
        </div>

        <div class="sm:col-span-2 lg:col-span-4">
            <flux:button wire:click="resetFilters" icon="arrow-uturn-left" size="sm" variant="{{ $hasActiveFilters ? 'filled' : 'ghost' }}" data-test="reset-filters">
                Reset Filters
            </flux:button>
        </div>
    </div>

    <div wire:loading wire:target="{{ $loadingTargets }}" class="flex w-full justify-center py-2">
        <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
    </div>

    <!-- Orders Table -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
         wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $loadingTargets }}">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[940px] table-fixed text-left text-sm text-zinc-600 dark:text-zinc-400">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                        <th scope="col" class="px-4 py-4 w-[14%]">PO Number</th>
                        <th scope="col" class="px-4 py-4 w-[17%]">Supplier</th>
                        <th scope="col" class="px-4 py-4 w-[17%]">Dates</th>
                        <th scope="col" class="px-4 py-4 text-left w-[8%]">Items</th>
                        <th scope="col" class="px-4 py-4 w-[10%]">Received</th>
                        <th scope="col" class="px-4 py-4 text-left w-[11%]">Total</th>
                        <th scope="col" class="px-4 py-4 w-[15%]">Status</th>
                        <th scope="col" class="px-4 py-4 text-left w-[8%]">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($orders as $order)
                        @php
                            $ordered = (int) ($order->ordered_units ?? 0);
                            $received = (int) ($order->received_units ?? 0);
                            $progress = $ordered > 0 ? min(100, (int) round($received / $ordered * 100)) : 0;
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="po-{{ $order->id }}">
                            <td class="px-4 py-4 whitespace-nowrap">
                                <a href="{{ route('purchase-orders.show', $order) }}" wire:navigate class="font-mono text-sm font-semibold tracking-tight text-indigo-600 hover:underline dark:text-indigo-400">
                                    {{ $order->po_number }}
                                </a>
                                <flux:text class="block truncate text-[10px] text-zinc-400" title="{{ $order->creator->name ?? 'Unknown' }}">by {{ $order->creator->name ?? 'Unknown' }}</flux:text>
                            </td>
                            <td class="px-4 py-4">
                                <flux:text
                                    class="block truncate overflow-hidden text-ellipsis font-medium text-zinc-900 dark:text-white"
                                    title="{{ $order->supplier->name ?? 'Unassigned' }}"
                                >{{ $order->supplier->name ?? 'Unassigned' }}</flux:text>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <flux:text class="text-xs">Ordered: {{ $order->order_date->format('M d, Y') }}</flux:text>
                                <flux:text class="block text-xs {{ $order->isOverdue() ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-zinc-500' }}">
                                    Expected: {{ $order->expected_delivery_date?->format('M d, Y') ?? '—' }}
                                    @if($order->isOverdue()) (overdue) @endif
                                </flux:text>
                            </td>
                            <td class="px-4 py-4 text-right">{{ number_format($order->items_count) }}</td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div class="h-full rounded-full {{ $progress === 100 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $progress }}%"></div>
                                    </div>
                                    <span class="text-xs whitespace-nowrap">{{ number_format($received) }}/{{ number_format($ordered) }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-right font-semibold whitespace-nowrap text-zinc-900 dark:text-white">${{ number_format((float) $order->total_amount, 2) }}</td>
                            <td class="px-4 py-4">
                                <flux:badge :color="$order->statusColor()" size="sm" class="whitespace-nowrap">{{ $order->statusLabel() }}</flux:badge>
                            </td>
                            <td class="px-4 py-4">
                                @php
                                    $isSubmitted = $order->status === \App\Models\PurchaseOrder::STATUS_SUBMITTED;
                                    $canEditRow = $canManage && $order->isEditable();
                                    $canApproveRow = $canApprove && $isSubmitted;
                                    $canReceiveRow = $canReceive && $order->isReceivable();
                                    $canCancelRow = $canManage && $order->isOpen();
                                    $hasRowActions = $canEditRow || $canApproveRow || $canReceiveRow || $canCancelRow;
                                @endphp
                                <div class="flex items-center gap-1">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="eye"
                                        href="{{ route('purchase-orders.show', $order) }}"
                                        wire:navigate
                                        title="View purchase order"
                                        aria-label="View purchase order {{ $order->po_number }}"
                                    />

                                    @if($hasRowActions)
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="ellipsis-vertical"
                                                title="More actions"
                                                aria-label="More actions for purchase order {{ $order->po_number }}"
                                                data-test="row-menu-{{ $order->id }}"
                                            />

                                            <flux:menu>
                                                @if($canEditRow)
                                                    <flux:menu.item icon="pencil-square" wire:click="openEditModal({{ $order->id }})">Edit</flux:menu.item>
                                                    <flux:menu.item icon="paper-airplane" wire:click="submitOrder({{ $order->id }})" data-test="submit-{{ $order->id }}">Submit for Approval</flux:menu.item>
                                                @endif

                                                @if($canApproveRow)
                                                    <flux:menu.item icon="check" wire:click="approveOrder({{ $order->id }})" data-test="approve-{{ $order->id }}">Approve</flux:menu.item>
                                                @endif

                                                @if($canReceiveRow)
                                                    <flux:menu.item icon="truck" href="{{ route('purchase-orders.show', $order) }}" wire:navigate>Receive Goods</flux:menu.item>
                                                @endif

                                                @if($canCancelRow)
                                                    @if($canEditRow || $canApproveRow || $canReceiveRow)
                                                        <flux:menu.separator />
                                                    @endif
                                                    <flux:menu.item
                                                        icon="x-mark"
                                                        variant="danger"
                                                        wire:click="cancelOrder({{ $order->id }})"
                                                        wire:confirm="Cancel this purchase order?"
                                                        data-test="cancel-{{ $order->id }}"
                                                    >Cancel Order</flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <flux:icon name="clipboard-document-list" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                    <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No Purchase Orders</flux:heading>
                                    <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Raise your first order to start tracking supplier deliveries.</flux:text>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Modal -->
    @if($showFormModal)
        <flux:modal wire:model="showFormModal" class="w-full max-w-3xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ $editingId ? 'Edit Purchase Order' : 'New Purchase Order' }}</flux:heading>
                    <flux:subheading>Orders start as drafts. Submit for approval once the line items are correct.</flux:subheading>
                </div>

                <form wire:submit.prevent="save" class="space-y-4" novalidate>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:field>
                            <flux:label class="mb-1">Supplier <span class="text-rose-500">*</span></flux:label>
                            <flux:select wire:model.live="supplierId">
                                <option value="">Choose a supplier...</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="supplierId" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field>
                            <flux:label class="mb-1">Order Date <span class="text-rose-500">*</span></flux:label>
                            <flux:input wire:model="orderDate" type="date" />
                            <flux:error name="orderDate" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field>
                            <flux:label class="mb-1">Expected Delivery</flux:label>
                            <flux:input wire:model="expectedDeliveryDate" type="date" />
                            <flux:error name="expectedDeliveryDate" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>
                    </div>

                    <!-- Line Items -->
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950">
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Line Items</span>
                            <flux:button size="xs" variant="filled" icon="plus" wire:click="addLineItem" type="button" data-test="add-line-item">Add Item</flux:button>
                        </div>
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @foreach($lineItems as $index => $line)
                                <div class="grid grid-cols-1 items-end gap-3 p-4 sm:grid-cols-12" wire:key="line-{{ $index }}">
                                    <div class="sm:col-span-6">
                                        <flux:field>
                                            <flux:label class="mb-1 text-xs">Product</flux:label>
                                            <flux:select wire:model.live="lineItems.{{ $index }}.product_id">
                                                <option value="">Select a product...</option>
                                                @foreach($formProducts as $product)
                                                    <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>
                                                @endforeach
                                            </flux:select>
                                            <flux:error name="lineItems.{{ $index }}.product_id" class="!mt-0.5 text-xs font-medium" />
                                        </flux:field>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <flux:field>
                                            <flux:label class="mb-1 text-xs">Quantity</flux:label>
                                            <flux:input wire:model.live="lineItems.{{ $index }}.quantity_ordered" type="number" min="1" />
                                            <flux:error name="lineItems.{{ $index }}.quantity_ordered" class="!mt-0.5 text-xs font-medium" />
                                        </flux:field>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <flux:field>
                                            <flux:label class="mb-1 text-xs">Unit Cost</flux:label>
                                            <flux:input wire:model.live="lineItems.{{ $index }}.unit_cost" type="number" step="0.01" min="0" />
                                            <flux:error name="lineItems.{{ $index }}.unit_cost" class="!mt-0.5 text-xs font-medium" />
                                        </flux:field>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 sm:col-span-2">
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            ${{ number_format((float) ($line['quantity_ordered'] ?: 0) * (float) ($line['unit_cost'] ?: 0), 2) }}
                                        </span>
                                        <flux:button size="xs" variant="subtle" icon="trash" type="button" wire:click="removeLineItem({{ $index }})" aria-label="Remove line item" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950">
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Grand Total</span>
                            <span class="text-base font-bold text-zinc-900 dark:text-white" data-test="draft-total">${{ number_format((float) $draftTotal, 2) }}</span>
                        </div>
                    </div>
                    <flux:error name="lineItems" class="!mt-0.5 text-xs font-medium" />

                    <flux:field>
                        <flux:label class="mb-1">Notes</flux:label>
                        <flux:textarea wire:model="notes" rows="3" placeholder="Delivery instructions, agreed terms, references..." />
                        <flux:error name="notes" class="!mt-0.5 text-xs font-medium" />
                    </flux:field>

                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showFormModal', false)" variant="ghost" type="button">Cancel</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="save-purchase-order">
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update Order' : 'Create Order' }}</span>
                            <span wire:loading wire:target="save" class="flex items-center gap-2">
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
