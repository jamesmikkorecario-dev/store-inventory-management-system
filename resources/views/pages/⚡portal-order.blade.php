<?php

use App\Models\PurchaseOrder;
use App\Services\SupplierPortalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Order')] class extends Component {
    public PurchaseOrder $order;

    /**
     * Resolve the order through the supplier-scoped lookup rather than route model
     * binding, so an order belonging to another supplier 404s instead of loading.
     */
    public function mount(int $purchaseOrder): void
    {
        $supplier = Auth::user()?->supplier;

        abort_if($supplier === null, 403);

        $found = app(SupplierPortalService::class)->findPurchaseOrder($supplier, $purchaseOrder);

        abort_if($found === null, 404);

        $this->order = $found;
    }

    public function with(): array
    {
        return [
            'timeline' => [
                ['label' => 'Order raised', 'at' => $this->order->created_at, 'icon' => 'document-plus'],
                ['label' => 'Submitted for approval', 'at' => $this->order->submitted_at, 'icon' => 'paper-airplane'],
                ['label' => 'Approved', 'at' => $this->order->approved_at, 'icon' => 'check-badge'],
                ['label' => 'Last delivery received', 'at' => $this->order->received_at, 'icon' => 'truck'],
                ['label' => 'Cancelled', 'at' => $this->order->cancelled_at, 'icon' => 'x-circle'],
            ],
        ];
    }
}; ?>

<div class="space-y-6">
    <!-- Heading -->
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-2">
            <flux:button size="sm" variant="ghost" icon="arrow-left" href="{{ route('portal.orders.index') }}" wire:navigate>Back to My Purchase Orders</flux:button>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" class="font-mono font-bold">{{ $order->po_number }}</flux:heading>
                <flux:badge :color="$order->statusColor()" size="sm" data-test="po-status">{{ $order->statusLabel() }}</flux:badge>
                @if($order->isOverdue())
                    <flux:badge color="rose" size="sm" icon="exclamation-triangle">Overdue</flux:badge>
                @endif
            </div>
            <flux:subheading>
                Ordered {{ $order->order_date->format('M d, Y') }} · Expected {{ $order->expected_delivery_date?->format('M d, Y') ?? 'not set' }}
            </flux:subheading>
        </div>

        <flux:button size="sm" variant="primary" icon="arrow-down-tray" href="{{ route('portal.orders.pdf', $order) }}" data-test="download-pdf">Download PDF</flux:button>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-report-stat-card label="Order Total" icon="banknotes" icon-class="text-emerald-500" :value="'$' . number_format((float) $order->total_amount, 2)" :hint="$order->items->count() . ' line item(s)'" />
        <x-report-stat-card label="Units Ordered" icon="cube" icon-class="text-indigo-500" :value="number_format($order->totalOrdered())" hint="Across all lines" />
        <x-report-stat-card label="Units Delivered" icon="truck" icon-class="text-amber-500" :value="number_format($order->totalReceived())" :hint="$order->receivedPercentage() . '% complete'" />
        <x-report-stat-card
            label="Expected Delivery"
            icon="calendar-days"
            :icon-class="$order->isOverdue() ? 'text-rose-500' : 'text-sky-500'"
            :value="$order->expected_delivery_date?->format('M d, Y') ?? 'Not set'"
            :hint="$order->isOverdue() ? 'Past due' : 'On schedule'"
            :hint-class="$order->isOverdue() ? 'text-rose-500' : 'text-zinc-500'"
        />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Line items -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Line Items</span>
                <span class="text-xs text-zinc-400">{{ $order->receivedPercentage() }}% delivered</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] table-fixed text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-4 py-4 w-[28%]">Product</th>
                            <th scope="col" class="px-4 py-4 w-[14%]">Ordered</th>
                            <th scope="col" class="px-4 py-4 w-[14%]">Delivered</th>
                            <th scope="col" class="px-4 py-4 w-[15%]">Outstanding</th>
                            <th scope="col" class="px-4 py-4 w-[14%]">Unit Cost</th>
                            <th scope="col" class="px-4 py-4 w-[15%]">Line Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($order->items as $item)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="item-{{ $item->id }}">
                                <td class="px-4 py-3.5">
                                    <flux:text class="block truncate font-semibold text-zinc-900 dark:text-white" title="{{ $item->product->name ?? 'Deleted product' }}">{{ $item->product->name ?? 'Deleted product' }}</flux:text>
                                    <flux:text class="block truncate font-mono text-[11px] text-zinc-400">{{ $item->product->sku ?? '' }}</flux:text>
                                </td>
                                <td class="px-4 py-3.5 font-medium">{{ number_format($item->quantity_ordered) }}</td>
                                <td class="px-4 py-3.5 font-medium {{ $item->isFullyReceived() ? 'text-emerald-600 dark:text-emerald-400' : '' }}">{{ number_format($item->quantity_received) }}</td>
                                <td class="px-4 py-3.5">{{ number_format($item->outstandingQuantity()) }}</td>
                                <td class="px-4 py-3.5 whitespace-nowrap">${{ number_format((float) $item->unit_cost, 2) }}</td>
                                <td class="px-4 py-3.5 whitespace-nowrap font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $item->line_total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">This purchase order has no line items.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($order->items->isNotEmpty())
                        <tfoot>
                            <tr class="border-t-2 border-zinc-300 bg-zinc-100 font-bold text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-white">
                                <td class="px-4 py-4">GRAND TOTAL</td>
                                <td class="px-4 py-4">{{ number_format($order->totalOrdered()) }}</td>
                                <td class="px-4 py-4">{{ number_format($order->totalReceived()) }}</td>
                                <td class="px-4 py-4"></td>
                                <td class="px-4 py-4"></td>
                                <td class="px-4 py-4 whitespace-nowrap">${{ number_format((float) $order->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <!-- Side panel -->
        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                    <flux:icon name="clock" class="size-4 text-indigo-500" />
                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Status Timeline</span>
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
                </div>
            </div>

            @if($order->notes)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                        <flux:icon name="document-text" class="size-4 text-zinc-500" />
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Notes</span>
                    </div>
                    <flux:text class="mt-4 block text-sm whitespace-pre-line text-zinc-600 dark:text-zinc-400">{{ $order->notes }}</flux:text>
                </div>
            @endif
        </div>
    </div>
</div>
