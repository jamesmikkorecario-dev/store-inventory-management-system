<?php

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Order')] class extends Component {
    public PurchaseOrder $purchaseOrder;

    public bool $showReceiveModal = false;

    /**
     * Quantities to receive, keyed by purchase order item id.
     *
     * @var array<int, string>
     */
    public array $receiveQuantities = [];

    public bool $canManage = false;
    public bool $canApprove = false;
    public bool $canReceive = false;

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $user = Auth::user();

        $this->purchaseOrder = $purchaseOrder->load(['supplier', 'creator', 'approver', 'items.product']);
        $this->canManage = (bool) $user?->can('manage purchase orders');
        $this->canApprove = (bool) $user?->can('approve purchase orders');
        $this->canReceive = (bool) $user?->can('receive purchase orders');
    }

    public function openReceiveModal(): void
    {
        abort_unless($this->canReceive, 403);

        if (! $this->purchaseOrder->isReceivable()) {
            Flux::toast(variant: 'warning', text: 'Only approved or partially received orders can be received.');

            return;
        }

        $this->resetValidation();

        $this->receiveQuantities = $this->purchaseOrder->items
            ->mapWithKeys(fn ($item): array => [$item->id => (string) $item->outstandingQuantity()])
            ->all();

        $this->showReceiveModal = true;
    }

    public function receive(PurchaseOrderService $service): void
    {
        abort_unless($this->canReceive, 403);

        $this->validate([
            'receiveQuantities' => 'required|array',
            'receiveQuantities.*' => 'nullable|integer|min:0',
        ], [
            'receiveQuantities.*.integer' => 'Quantities must be whole numbers.',
            'receiveQuantities.*.min' => 'Quantities cannot be negative.',
        ]);

        try {
            $this->purchaseOrder = $service->receive($this->purchaseOrder, $this->receiveQuantities, Auth::user());
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->dispatch('purchase-orders-updated');
        $this->dispatch('transactions-updated');
        $this->dispatch('products-updated');
        $this->dispatch('inventory-updated');
        $this->dispatch('alerts-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(variant: 'success', text: 'Delivery recorded and stock levels updated.');

        $this->showReceiveModal = false;
        $this->receiveQuantities = [];
    }

    public function submitOrder(PurchaseOrderService $service): void
    {
        abort_unless($this->canManage, 403);

        $this->runWorkflowAction(fn () => $service->submit($this->purchaseOrder), 'Purchase order submitted for approval.');
    }

    public function revertToDraft(PurchaseOrderService $service): void
    {
        abort_unless($this->canManage, 403);

        $this->runWorkflowAction(fn () => $service->revertToDraft($this->purchaseOrder), 'Purchase order returned to draft.');
    }

    public function approveOrder(PurchaseOrderService $service): void
    {
        abort_unless($this->canApprove, 403);

        $this->runWorkflowAction(fn () => $service->approve($this->purchaseOrder, Auth::user()), 'Purchase order approved.');
    }

    public function cancelOrder(PurchaseOrderService $service): void
    {
        abort_unless($this->canManage, 403);

        $this->runWorkflowAction(fn () => $service->cancel($this->purchaseOrder), 'Purchase order cancelled.');
    }

    public function with(): array
    {
        $this->purchaseOrder->load(['supplier', 'creator', 'approver', 'items.product']);

        return [
            'timeline' => [
                ['label' => 'Created', 'at' => $this->purchaseOrder->created_at, 'icon' => 'document-plus'],
                ['label' => 'Submitted', 'at' => $this->purchaseOrder->submitted_at, 'icon' => 'paper-airplane'],
                ['label' => 'Approved', 'at' => $this->purchaseOrder->approved_at, 'icon' => 'check-badge'],
                ['label' => 'Last Receipt', 'at' => $this->purchaseOrder->received_at, 'icon' => 'truck'],
                ['label' => 'Cancelled', 'at' => $this->purchaseOrder->cancelled_at, 'icon' => 'x-circle'],
            ],
            'receivePreview' => collect($this->receiveQuantities)->sum(fn (mixed $value): int => (int) $value),
        ];
    }

    private function runWorkflowAction(callable $action, string $message): void
    {
        try {
            $this->purchaseOrder = $action();
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->dispatch('purchase-orders-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="space-y-6">
    <!-- Heading -->
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-2">
            <flux:button size="sm" variant="ghost" icon="arrow-left" href="{{ route('purchase-orders.index') }}" wire:navigate>Back to Purchase Orders</flux:button>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" class="font-mono font-bold">{{ $purchaseOrder->po_number }}</flux:heading>
                <flux:badge :color="$purchaseOrder->statusColor()" size="sm" data-test="po-status">{{ $purchaseOrder->statusLabel() }}</flux:badge>
                @if($purchaseOrder->isOverdue())
                    <flux:badge color="rose" size="sm" icon="exclamation-triangle">Overdue</flux:badge>
                @endif
            </div>
            <flux:subheading>
                {{ $purchaseOrder->supplier->name ?? 'Unassigned supplier' }} · Raised by {{ $purchaseOrder->creator->name ?? 'Unknown' }} on {{ $purchaseOrder->order_date->format('M d, Y') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if($canManage && $purchaseOrder->status === \App\Models\PurchaseOrder::STATUS_DRAFT)
                <flux:button size="sm" variant="primary" icon="paper-airplane" wire:click="submitOrder" data-test="submit-order">Submit for Approval</flux:button>
            @endif
            @if($canManage && $purchaseOrder->status === \App\Models\PurchaseOrder::STATUS_SUBMITTED)
                <flux:button size="sm" variant="filled" icon="arrow-uturn-left" wire:click="revertToDraft" data-test="revert-order">Return to Draft</flux:button>
            @endif
            @if($canApprove && $purchaseOrder->status === \App\Models\PurchaseOrder::STATUS_SUBMITTED)
                <flux:button size="sm" variant="primary" icon="check" wire:click="approveOrder" data-test="approve-order">Approve</flux:button>
            @endif
            @if($canReceive && $purchaseOrder->isReceivable())
                <flux:button size="sm" variant="primary" icon="truck" wire:click="openReceiveModal" data-test="open-receive">Receive Goods</flux:button>
            @endif
            @if($canManage && $purchaseOrder->isOpen())
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelOrder" wire:confirm="Cancel this purchase order?" data-test="cancel-order">Cancel Order</flux:button>
            @endif
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-report-stat-card
            label="Order Total"
            icon="banknotes"
            icon-class="text-emerald-500"
            :value="'$' . number_format((float) $purchaseOrder->total_amount, 2)"
            :hint="$purchaseOrder->items->count() . ' line item(s)'"
        />
        <x-report-stat-card
            label="Units Ordered"
            icon="cube"
            icon-class="text-indigo-500"
            :value="number_format($purchaseOrder->totalOrdered())"
            hint="Across all lines"
        />
        <x-report-stat-card
            label="Units Received"
            icon="truck"
            icon-class="text-amber-500"
            :value="number_format($purchaseOrder->totalReceived())"
            :hint="$purchaseOrder->receivedPercentage() . '% complete'"
        />
        <x-report-stat-card
            label="Expected Delivery"
            icon="calendar-days"
            :icon-class="$purchaseOrder->isOverdue() ? 'text-rose-500' : 'text-sky-500'"
            :value="$purchaseOrder->expected_delivery_date?->format('M d, Y') ?? 'Not set'"
            :hint="$purchaseOrder->isOverdue() ? 'Past due' : 'On schedule'"
            :hint-class="$purchaseOrder->isOverdue() ? 'text-rose-500' : 'text-zinc-500'"
        />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Line Items -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Line Items</span>
                <span class="text-xs text-zinc-400">{{ $purchaseOrder->receivedPercentage() }}% received</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-6 py-4">Product</th>
                            <th scope="col" class="px-6 py-4 text-left">Ordered</th>
                            <th scope="col" class="px-6 py-4 text-left">Received</th>
                            <th scope="col" class="px-6 py-4 text-left">Outstanding</th>
                            <th scope="col" class="px-6 py-4 text-left">Unit Cost</th>
                            <th scope="col" class="px-6 py-4 text-left">Line Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($purchaseOrder->items as $item)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="item-{{ $item->id }}">
                                <td class="px-6 py-3.5">
                                    <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $item->product->name ?? 'Deleted Product' }}</flux:text>
                                    <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $item->product->sku ?? '' }}</flux:text>
                                </td>
                                <td class="px-6 py-3.5 text-left font-medium">{{ number_format($item->quantity_ordered) }}</td>
                                <td class="px-6 py-3.5 text-left font-medium {{ $item->isFullyReceived() ? 'text-emerald-600 dark:text-emerald-400' : '' }}">{{ number_format($item->quantity_received) }}</td>
                                <td class="px-6 py-3.5 text-left">{{ number_format($item->outstandingQuantity()) }}</td>
                                <td class="px-6 py-3.5 text-left whitespace-nowrap">${{ number_format((float) $item->unit_cost, 2) }}</td>
                                <td class="px-6 py-3.5 text-left font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $item->line_total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">This purchase order has no line items.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($purchaseOrder->items->isNotEmpty())
                        <tfoot>
                            <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                <td class="px-6 py-4">GRAND TOTAL</td>
                                <td class="px-6 py-4 text-left">{{ number_format($purchaseOrder->totalOrdered()) }}</td>
                                <td class="px-6 py-4 text-left">{{ number_format($purchaseOrder->totalReceived()) }}</td>
                                <td class="px-6 py-4"></td>
                                <td class="px-6 py-4"></td>
                                <td class="px-6 py-4 text-left">${{ number_format((float) $purchaseOrder->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <!-- Side Panel -->
        <div class="space-y-6">
            <!-- Supplier -->
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <flux:icon name="truck" class="size-4 text-pink-500" />
                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Supplier</span>
                </div>
                <div class="mt-4 space-y-1">
                    <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $purchaseOrder->supplier->name ?? 'Unassigned' }}</flux:text>
                    <flux:text class="block text-xs text-zinc-500">{{ $purchaseOrder->supplier->contact_person ?? '—' }}</flux:text>
                    <flux:text class="block text-xs text-zinc-500">{{ $purchaseOrder->supplier->email ?? '—' }}</flux:text>
                    <flux:text class="block text-xs text-zinc-500">{{ $purchaseOrder->supplier->phone ?? '—' }}</flux:text>
                </div>
            </div>

            <!-- Timeline -->
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <flux:icon name="clock" class="size-4 text-indigo-500" />
                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Workflow Timeline</span>
                </div>
                <div class="mt-4 space-y-3">
                    @foreach($timeline as $step)
                        @if($step['at'])
                            <div class="flex items-start gap-3" wire:key="step-{{ $step['label'] }}">
                                <flux:icon :name="$step['icon']" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-900 dark:text-white">{{ $step['label'] }}</flux:text>
                                    <flux:text class="block text-xs text-zinc-500">{{ $step['at']->format('M d, Y h:i A') }}</flux:text>
                                </div>
                            </div>
                        @endif
                    @endforeach
                    @if($purchaseOrder->approver)
                        <div class="flex items-start gap-3 border-t border-zinc-150 pt-3 dark:border-zinc-800">
                            <flux:icon name="user-circle" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-sm font-medium text-zinc-900 dark:text-white">Approved by</flux:text>
                                <flux:text class="block text-xs text-zinc-500">{{ $purchaseOrder->approver->name }}</flux:text>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Notes -->
            @if($purchaseOrder->notes)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                        <flux:icon name="document-text" class="size-4 text-zinc-500" />
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Notes</span>
                    </div>
                    <flux:text class="mt-4 block text-sm whitespace-pre-line text-zinc-600 dark:text-zinc-400">{{ $purchaseOrder->notes }}</flux:text>
                </div>
            @endif
        </div>
    </div>

    <!-- Receiving Modal -->
    @if($showReceiveModal)
        <flux:modal wire:model="showReceiveModal" class="w-full max-w-2xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Receive Goods</flux:heading>
                    <flux:subheading>Enter the quantities delivered. Each line creates a stock in transaction and raises the product's stock level.</flux:subheading>
                </div>

                <form wire:submit.prevent="receive" class="space-y-4" novalidate>
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                            <thead>
                                <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                                    <th scope="col" class="px-4 py-3">Product</th>
                                    <th scope="col" class="px-4 py-3 text-left">Ordered</th>
                                    <th scope="col" class="px-4 py-3 text-left">Outstanding</th>
                                    <th scope="col" class="px-4 py-3 text-left">Receive Now</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($purchaseOrder->items as $item)
                                    <tr wire:key="receive-{{ $item->id }}">
                                        <td class="px-4 py-3">
                                            <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $item->product->name ?? 'Deleted Product' }}</flux:text>
                                            <flux:text class="block font-mono text-[11px] text-zinc-400">{{ $item->product->sku ?? '' }}</flux:text>
                                        </td>
                                        <td class="px-4 py-3 text-left">{{ number_format($item->quantity_ordered) }}</td>
                                        <td class="px-4 py-3 text-left font-semibold">{{ number_format($item->outstandingQuantity()) }}</td>
                                        <td class="px-4 py-3">
                                            <flux:input
                                                wire:model.live="receiveQuantities.{{ $item->id }}"
                                                type="number"
                                                min="0"
                                                :max="$item->outstandingQuantity()"
                                                :disabled="$item->isFullyReceived()"
                                                class="max-w-24"
                                            />
                                            <flux:error name="receiveQuantities.{{ $item->id }}" class="!mt-0.5 text-xs font-medium" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-zinc-200 bg-zinc-50 font-semibold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                    <td class="px-4 py-3" colspan="3">Total units in this delivery</td>
                                    <td class="px-4 py-3 text-left" data-test="receive-preview">{{ number_format($receivePreview) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showReceiveModal', false)" variant="ghost" type="button">Cancel</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="confirm-receive">
                            <span wire:loading.remove wire:target="receive">Record Delivery</span>
                            <span wire:loading wire:target="receive" class="flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                Receiving...
                            </span>
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</div>
