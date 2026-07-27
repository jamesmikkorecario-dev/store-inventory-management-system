<div>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Alerts') }}</flux:heading>
            <flux:subheading>{{ __('Manage your low stock notifications') }}</flux:subheading>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Active Alerts</flux:text>
                <flux:icon name="bell" class="size-6 text-indigo-500" />
            </div>
            <div class="mt-4 flex items-baseline justify-between">
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $activeCount }}</flux:heading>
            </div>
        </div>

        <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Critical Alerts</flux:text>
                <flux:icon name="exclamation-triangle" class="size-6 text-rose-500" />
            </div>
            <div class="mt-4 flex items-baseline justify-between">
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $criticalCount }}</flux:heading>
            </div>
        </div>

        <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Low Stock Alerts</flux:text>
                <flux:icon name="exclamation-circle" class="size-6 text-amber-500" />
            </div>
            <div class="mt-4 flex items-baseline justify-between">
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $lowStockCount }}</flux:heading>
            </div>
        </div>

        <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Unread Alerts</flux:text>
                <flux:icon name="envelope" class="size-6 text-blue-500" />
            </div>
            <div class="mt-4 flex items-baseline justify-between">
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">{{ $unreadCount }}</flux:heading>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="flex-1 relative">
            <flux:input wire:model.live.debounce.300ms="search" label="Search Products" placeholder="Search product name or SKU..." icon="magnifying-glass" />
            <div wire:loading wire:target="search" class="absolute right-3 top-9">
                <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
            </div>
        </div>
        
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="status" label="Status">
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="resolved">Resolved</option>
            </flux:select>
        </div>
        
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="readStatus" label="Read Status">
                <option value="all">All Read Status</option>
                <option value="unread">Unread</option>
                <option value="read">Read</option>
            </flux:select>
        </div>
        
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="severity" label="Severity">
                <option value="all">All Severities</option>
                <option value="critical">Critical</option>
                <option value="low">Low</option>
            </flux:select>
        </div>
        
        <div class="w-full sm:w-auto">
            <flux:button wire:click="markAllAsRead" icon="check-circle" variant="subtle" class="w-full sm:w-auto h-10">Mark all as read</flux:button>
        </div>
    </div>

    <!-- Alerts Table -->
    <div wire:loading wire:target="search, status, readStatus, severity, gotoPage, nextPage, previousPage" class="flex justify-center py-4 w-full">
        <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
    </div>
    
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:loading.class="opacity-50 pointer-events-none" wire:target="search, status, readStatus, severity, gotoPage, nextPage, previousPage">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                        <th scope="col" class="px-6 py-4 whitespace-nowrap">Time</th>
                        <th scope="col" class="px-6 py-4 whitespace-nowrap">Product</th>
                        <th scope="col" class="px-6 py-4 whitespace-nowrap text-left">Current Stock</th>
                        <th scope="col" class="px-6 py-4 whitespace-nowrap text-left">Min Stock</th>
                        <th scope="col" class="px-6 py-4 whitespace-nowrap text-center">Severity</th>
                        <th scope="col" class="px-6 py-4 whitespace-nowrap text-center">Status</th>
                        <th scope="col" class="px-6 py-4 whitespace-nowrap text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($alerts as $alert)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30 {{ is_null($alert->read_at) ? 'bg-blue-50/30 dark:bg-blue-900/10' : '' }}">
                            <td class="px-6 py-4 whitespace-nowrap text-zinc-500 text-xs">
                                {{ $alert->created_at->diffForHumans() }}
                            </td>
                            
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $alert->product->name ?? 'Deleted Product' }}
                            </td>
                            
                            <td class="px-6 py-4 text-left font-semibold text-zinc-900 dark:text-zinc-100">
                                <span class="sr-only">Stock is at</span>
                                {{ $alert->current_stock }}
                            </td>
                            
                            <td class="px-6 py-4 text-left text-zinc-500">
                                {{ $alert->threshold }}
                            </td>
                            
                            <td class="px-6 py-4 text-center whitespace-nowrap">
                                @if($alert->severity === 'critical')
                                    <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">Critical</span>
                                @else
                                    <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">Low</span>
                                @endif
                            </td>
                            
                            <td class="px-6 py-4 text-center whitespace-nowrap">
                                <div class="flex items-center justify-center gap-2">
                                    @if($alert->resolved_at)
                                        <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">Resolved</span>
                                    @else
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">Active</span>
                                    @endif
                                    
                                    @if(is_null($alert->read_at))
                                        <span class="rounded bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-950/30 dark:text-blue-400">Unread</span>
                                    @endif
                                </div>
                            </td>
                            
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <flux:dropdown position="bottom-end" class="mx-auto">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" class="h-8 w-8" />
                                    <flux:menu>
                                        @if(is_null($alert->read_at))
                                            <flux:menu.item wire:click="markAsRead({{ $alert->id }})" icon="check">Mark as Read</flux:menu.item>
                                        @endif
                                        @if($alert->product_id)
                                            <flux:menu.item href="{{ route('products.index') }}" wire:navigate icon="eye">View Product</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16">
                                <div class="flex flex-col items-center justify-center text-center">
                                    <flux:icon name="shield-check" class="size-12 text-zinc-300 dark:text-zinc-600 mb-4" />
                                    <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">Inventory looks healthy</flux:heading>
                                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">No active stock alerts were found.</flux:text>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($alerts->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                {{ $alerts->links() }}
            </div>
        @endif
    </div>
</div>
