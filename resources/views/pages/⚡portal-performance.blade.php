<?php

use App\Models\Supplier;
use App\Services\SupplierPortalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My Performance')] class extends Component {
    public ?Supplier $supplier = null;

    public function mount(): void
    {
        $this->supplier = Auth::user()?->supplier;
    }

    public function with(): array
    {
        if ($this->supplier === null) {
            return ['summary' => null, 'topProducts' => collect(), 'recentOrders' => collect()];
        }

        $portal = app(SupplierPortalService::class);

        return [
            'summary' => $portal->performanceSummary($this->supplier),
            'topProducts' => $portal->topSuppliedProducts($this->supplier, 8),
            'recentOrders' => $portal->recentPurchaseOrders($this->supplier, 5),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">My Performance</flux:heading>
            <flux:subheading>How your supply relationship is tracking across every purchase order raised with you.</flux:subheading>
        </div>
        @if($supplier)
            <flux:badge color="zinc" size="sm">{{ $supplier->name }}</flux:badge>
        @endif
    </div>

    @if($supplier === null)
        <x-portal-no-supplier />
    @else
        <!-- Headline metrics -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-report-stat-card
                label="Total Purchase Orders"
                icon="clipboard-document-list"
                icon-class="text-indigo-500"
                :value="number_format($summary['order_count'])"
                :hint="number_format($summary['open_orders']) . ' still open'"
            />
            <x-report-stat-card
                label="Units Supplied"
                icon="cube"
                icon-class="text-sky-500"
                :value="number_format($summary['units_received'])"
                :hint="number_format($summary['units_ordered']) . ' ordered'"
            />
            <x-report-stat-card
                label="Total Purchase Value"
                icon="banknotes"
                icon-class="text-emerald-500"
                :value="'$' . number_format($summary['purchase_value'], 2)"
                :hint="'$' . number_format($summary['received_value'], 2) . ' delivered'"
            />
            <x-report-stat-card
                label="Inventory Value"
                icon="archive-box"
                icon-class="text-violet-500"
                :value="'$' . number_format($summary['inventory_value'], 2)"
                hint="Your stock on their shelves"
            />
        </div>

        <!-- Fulfilment -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-150 pb-4 dark:border-zinc-800">
                <flux:icon name="chart-bar" class="size-5 text-emerald-500" />
                <flux:heading size="lg" class="font-semibold">Fulfilment</flux:heading>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-6 sm:grid-cols-4">
                <div class="flex flex-col">
                    <flux:text class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $summary['fulfilment_rate'] }}%</flux:text>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Units delivered</flux:text>
                </div>
                <div class="flex flex-col">
                    <flux:text class="text-3xl font-bold text-emerald-600 dark:text-emerald-500">{{ number_format($summary['received_orders']) }}</flux:text>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Completed orders</flux:text>
                </div>
                <div class="flex flex-col">
                    <flux:text class="text-3xl font-bold text-amber-600 dark:text-amber-500">{{ number_format($summary['outstanding_units']) }}</flux:text>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Units outstanding</flux:text>
                </div>
                <div class="flex flex-col">
                    <flux:text class="text-3xl font-bold text-rose-600 dark:text-rose-500">{{ number_format($summary['cancelled_orders']) }}</flux:text>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Cancelled orders</flux:text>
                </div>
            </div>

            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full rounded-full bg-emerald-500 transition-all duration-500" style="width: {{ min(100, $summary['fulfilment_rate']) }}%"></div>
            </div>

            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                <span>First order: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $summary['first_order_at']?->format('M d, Y') ?? '—' }}</span></span>
                <span>Most recent: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $summary['last_order_at']?->format('M d, Y') ?? '—' }}</span></span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Top supplied products -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <flux:icon name="trophy" class="size-4 text-amber-500" />
                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Top Supplied Products</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[420px] table-fixed text-left text-sm text-zinc-600 dark:text-zinc-400">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50/60 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950/50">
                                <th scope="col" class="px-4 py-3 w-[46%]">Product</th>
                                <th scope="col" class="px-4 py-3 w-[18%]">Delivered</th>
                                <th scope="col" class="px-4 py-3 w-[16%]">Ordered</th>
                                <th scope="col" class="px-4 py-3 w-[20%]">Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($topProducts as $product)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="top-{{ $product->id }}">
                                    <td class="px-4 py-3">
                                        <flux:text class="block truncate font-medium text-zinc-900 dark:text-white" title="{{ $product->name }}">{{ $product->name }}</flux:text>
                                        <flux:text class="block truncate font-mono text-[11px] text-zinc-400">{{ $product->sku }}</flux:text>
                                    </td>
                                    <td class="px-4 py-3 font-semibold">{{ number_format((int) $product->units_received) }}</td>
                                    <td class="px-4 py-3">{{ number_format((int) $product->units_ordered) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">${{ number_format((float) $product->received_value, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-12">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="trophy" class="mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">Nothing supplied yet</flux:text>
                                            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Delivered units will be ranked here.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent orders -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
                    <div class="flex items-center gap-2">
                        <flux:icon name="clipboard-document-list" class="size-4 text-indigo-500" />
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Recent Purchase Orders</span>
                    </div>
                    <flux:button variant="subtle" size="sm" href="{{ route('portal.orders.index') }}" wire:navigate>View all</flux:button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[420px] table-fixed text-left text-sm text-zinc-600 dark:text-zinc-400">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50/60 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950/50">
                                <th scope="col" class="px-4 py-3 w-[30%]">PO Number</th>
                                <th scope="col" class="px-4 py-3 w-[24%]">Ordered</th>
                                <th scope="col" class="px-4 py-3 w-[22%]">Value</th>
                                <th scope="col" class="px-4 py-3 w-[24%] text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse($recentOrders as $order)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30" wire:key="recent-{{ $order->id }}">
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="{{ route('portal.orders.show', $order) }}" wire:navigate class="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ $order->po_number }}</a>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $order->order_date->format('M d, Y') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium text-zinc-900 dark:text-white">${{ number_format((float) $order->total_amount, 2) }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <flux:badge :color="$order->statusColor()" size="sm" class="whitespace-nowrap">{{ $order->statusLabel() }}</flux:badge>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-12">
                                        <div class="flex flex-col items-center justify-center text-center">
                                            <flux:icon name="clipboard-document-list" class="mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">No purchase orders</flux:text>
                                            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Orders raised with you will appear here.</flux:text>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
