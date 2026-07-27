<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\SupplierPortalService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My Purchase Orders')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterStatus = '';
    public string $filterStartDate = '';
    public string $filterEndDate = '';

    #[Locked]
    public ?Supplier $supplier = null;

    public function mount(): void
    {
        $this->supplier = Auth::user()?->supplier;
    }

    public function updated(string $property, mixed $value = null): void
    {
        if (in_array($property, ['search', 'filterStatus', 'filterStartDate', 'filterEndDate'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterStartDate', 'filterEndDate']);
        $this->resetPage();
    }

    public function with(): array
    {
        if ($this->supplier === null) {
            return ['orders' => null, 'statuses' => PurchaseOrder::STATUSES, 'metrics' => null, 'hasFilters' => false];
        }

        $portal = app(SupplierPortalService::class);

        return [
            'orders' => $portal->purchaseOrderQuery($this->supplier, [
                'search' => $this->search,
                'status' => $this->filterStatus,
                'startDate' => $this->filterStartDate,
                'endDate' => $this->filterEndDate,
            ])->paginate(15),
            'statuses' => PurchaseOrder::STATUSES,
            'metrics' => $portal->dashboardMetrics($this->supplier),
            'hasFilters' => $this->search !== '' || $this->filterStatus !== '' || $this->filterStartDate !== '' || $this->filterEndDate !== '',
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">My Purchase Orders</flux:heading>
            <flux:subheading>Orders raised with you, their delivery progress and downloadable documents.</flux:subheading>
        </div>
        <flux:badge color="zinc" size="sm" icon="lock-closed">Read only</flux:badge>
    </div>

    @if($supplier === null)
        <x-portal-no-supplier />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-report-stat-card label="Open Orders" icon="clipboard-document-list" icon-class="text-indigo-500" :value="number_format($metrics['open_purchase_orders'])" hint="Not yet completed" />
            <x-report-stat-card label="Awaiting Approval" icon="clock" icon-class="text-sky-500" :value="number_format($metrics['awaiting_approval'])" hint="Submitted to your buyer" />
            <x-report-stat-card label="Pending Deliveries" icon="truck" icon-class="text-amber-500" :value="number_format($metrics['pending_deliveries'])" hint="Approved, awaiting goods" />
        </div>

        <!-- Filters -->
        <div class="grid grid-cols-1 items-end gap-4 rounded-xl border border-zinc-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="relative sm:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="PO number..." icon="magnifying-glass" />
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
            <div class="sm:col-span-2 lg:col-span-3">
                <flux:button wire:click="resetFilters" icon="arrow-uturn-left" size="sm" variant="{{ $hasFilters ? 'filled' : 'ghost' }}" data-test="reset-filters">Reset Filters</flux:button>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] table-fixed text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-4 py-4 w-[16%]">PO Number</th>
                            <th scope="col" class="px-4 py-4 w-[22%]">Dates</th>
                            <th scope="col" class="px-4 py-4 w-[9%]">Items</th>
                            <th scope="col" class="px-4 py-4 w-[15%]">Delivered</th>
                            <th scope="col" class="px-4 py-4 w-[14%]">Order Value</th>
                            <th scope="col" class="px-4 py-4 w-[15%] text-center">Status</th>
                            <th scope="col" class="px-4 py-4 w-[9%] text-center">Actions</th>
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
                                    <a href="{{ route('portal.orders.show', $order) }}" wire:navigate class="font-mono text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                        {{ $order->po_number }}
                                    </a>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <flux:text class="text-xs">Ordered: {{ $order->order_date->format('M d, Y') }}</flux:text>
                                    <flux:text class="block text-xs {{ $order->isOverdue() ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-zinc-500' }}">
                                        Expected: {{ $order->expected_delivery_date?->format('M d, Y') ?? '—' }}
                                        @if($order->isOverdue()) (overdue) @endif
                                    </flux:text>
                                </td>
                                <td class="px-4 py-4">{{ number_format($order->items_count) }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-14 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                            <div class="h-full rounded-full {{ $progress === 100 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $progress }}%"></div>
                                        </div>
                                        <span class="text-xs whitespace-nowrap">{{ number_format($received) }}/{{ number_format($ordered) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $order->total_amount, 2) }}</td>
                                <td class="px-4 py-4 text-center">
                                    <flux:badge :color="$order->statusColor()" size="sm" class="whitespace-nowrap">{{ $order->statusLabel() }}</flux:badge>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <flux:button size="sm" variant="ghost" icon="eye" href="{{ route('portal.orders.show', $order) }}" wire:navigate title="View order" :aria-label="'View purchase order ' . $order->po_number" />
                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" href="{{ route('portal.orders.pdf', $order) }}" title="Download PDF" :aria-label="'Download PDF for purchase order ' . $order->po_number" data-test="pdf-{{ $order->id }}" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-16">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <flux:icon name="clipboard-document-list" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
                                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No purchase orders</flux:heading>
                                        <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ $hasFilters ? 'No order matches the current filters.' : 'No purchase orders have been raised with you yet.' }}
                                        </flux:text>
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
    @endif
</div>
