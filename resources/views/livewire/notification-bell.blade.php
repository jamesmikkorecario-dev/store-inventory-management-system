<div>
    <flux:dropdown position="bottom" align="end">
        <button
            type="button"
            class="relative inline-flex size-9 cursor-pointer items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
            aria-label="Notifications{{ $unreadCount > 0 ? ' (' . $unreadCount . ' unread)' : '' }}"
            title="Notifications"
            data-test="notification-bell"
        >
            <flux:icon name="bell" class="size-5" />
            @if($unreadCount > 0)
                <span
                    class="absolute -right-0.5 -top-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold leading-4 text-white"
                    data-test="notification-badge"
                >{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            @endif
        </button>

        <flux:menu class="w-80 sm:w-96">
            <div class="flex items-center justify-between gap-2 px-2 py-1.5">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Notifications</span>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        wire:click="toggleUnreadOnly"
                        class="cursor-pointer rounded px-2 py-0.5 text-[11px] font-medium {{ $unreadOnly ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white' }}"
                        data-test="bell-toggle-unread"
                    >{{ $unreadOnly ? 'Unread' : 'All' }}</button>
                    @if($unreadCount > 0)
                        <button
                            type="button"
                            wire:click="markAllAsRead"
                            class="cursor-pointer rounded px-2 py-0.5 text-[11px] font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                            data-test="bell-mark-all"
                        >Mark all read</button>
                    @endif
                </div>
            </div>

            <flux:menu.separator />

            <div class="max-h-80 overflow-y-auto">
                @forelse($notifications as $notification)
                    @php
                        $data = $notification->data;
                        $isUnread = $notification->read_at === null;
                    @endphp
                    <div
                        class="flex gap-3 px-2 py-2.5 {{ $isUnread ? 'bg-indigo-50/50 dark:bg-indigo-950/20' : '' }}"
                        wire:key="bell-{{ $notification->id }}"
                    >
                        <flux:icon
                            :name="$data['icon'] ?? 'bell'"
                            class="mt-0.5 size-4 shrink-0 {{ ($data['color'] ?? 'zinc') === 'rose' ? 'text-rose-500' : (($data['color'] ?? 'zinc') === 'amber' ? 'text-amber-500' : (($data['color'] ?? 'zinc') === 'emerald' ? 'text-emerald-500' : 'text-indigo-500')) }}"
                        />
                        <div class="min-w-0 flex-1">
                            <flux:text class="block truncate text-sm font-semibold text-zinc-900 dark:text-white" title="{{ $data['title'] ?? 'Notification' }}">
                                {{ $data['title'] ?? 'Notification' }}
                            </flux:text>
                            <flux:text class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ $data['message'] ?? '' }}</flux:text>
                            <div class="mt-1 flex items-center gap-2">
                                <flux:text class="text-[10px] uppercase tracking-wider text-zinc-400">{{ $notification->created_at->diffForHumans() }}</flux:text>
                                @if($isUnread)
                                    <button
                                        type="button"
                                        wire:click="markAsRead('{{ $notification->id }}')"
                                        class="cursor-pointer text-[10px] font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                        data-test="bell-read-{{ $notification->id }}"
                                    >Mark read</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center px-4 py-8 text-center">
                        <flux:icon name="check-circle" class="mb-2 size-8 text-emerald-500" />
                        <flux:text class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $unreadOnly ? 'No unread notifications' : 'No notifications yet' }}
                        </flux:text>
                        <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Inventory and order events will appear here.</flux:text>
                    </div>
                @endforelse
            </div>

            <flux:menu.separator />

            <flux:menu.item icon="inbox" href="{{ route('notifications.index') }}" wire:navigate>View all notifications</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
