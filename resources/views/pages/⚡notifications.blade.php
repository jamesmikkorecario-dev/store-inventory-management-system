<?php

use App\Models\User;
use App\Services\NotificationService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notifications')] class extends Component {
    use WithPagination;

    #[Url(as: 'filter', keep: true)]
    public string $filter = 'unread';

    public function mount(): void
    {
        if (! in_array($this->filter, ['unread', 'all'], true)) {
            $this->filter = 'unread';
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['unread', 'all'], true) ? $filter : 'unread';
        $this->resetPage();
    }

    public function markAsRead(string $notificationId): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        // Scoped through the user's own relation, so a foreign id simply misses.
        app(NotificationService::class)->markAsRead($user, $notificationId);

        $this->dispatch('notifications-updated');
    }

    public function markAllAsRead(): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        $count = app(NotificationService::class)->markAllAsRead($user);

        $this->dispatch('notifications-updated');

        Flux::toast(variant: 'success', text: $count === 0
            ? 'No unread notifications to clear.'
            : $count.' notification(s) marked as read.');
    }

    public function with(): array
    {
        $user = $this->currentUser();

        if ($user === null) {
            return ['notifications' => null, 'unreadCount' => 0, 'totalCount' => 0];
        }

        $query = $user->notifications()->getQuery();

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        }

        return [
            'notifications' => $query->paginate(15),
            'unreadCount' => $user->unreadNotifications()->count(),
            'totalCount' => $user->notifications()->count(),
        ];
    }

    private function currentUser(): ?User
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user;
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" class="font-bold">Notifications</flux:heading>
            <flux:subheading>Inventory alerts, stock forecasts and purchase order activity for your account.</flux:subheading>
        </div>
        @if($unreadCount > 0)
            <flux:button wire:click="markAllAsRead" icon="check" variant="primary" size="sm" data-test="mark-all-read">
                Mark all as read ({{ number_format($unreadCount) }})
            </flux:button>
        @endif
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="inline-flex items-center gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
            <button
                type="button"
                wire:click="setFilter('unread')"
                data-test="filter-unread"
                class="cursor-pointer rounded-md px-3 py-1.5 text-xs font-medium transition-all duration-150 {{ $filter === 'unread' ? 'bg-white font-semibold text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >Unread ({{ number_format($unreadCount) }})</button>
            <button
                type="button"
                wire:click="setFilter('all')"
                data-test="filter-all"
                class="cursor-pointer rounded-md px-3 py-1.5 text-xs font-medium transition-all duration-150 {{ $filter === 'all' ? 'bg-white font-semibold text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
            >All ({{ number_format($totalCount) }})</button>
        </div>
    </div>

    <!-- List -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
            @forelse($notifications as $notification)
                @php
                    $data = $notification->data;
                    $isUnread = $notification->read_at === null;
                    $color = $data['color'] ?? 'zinc';
                @endphp
                <div
                    class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between {{ $isUnread ? 'bg-indigo-50/40 dark:bg-indigo-950/20' : '' }}"
                    wire:key="notification-{{ $notification->id }}"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <flux:icon
                            :name="$data['icon'] ?? 'bell'"
                            class="mt-0.5 size-5 shrink-0 {{ $color === 'rose' ? 'text-rose-500' : ($color === 'amber' ? 'text-amber-500' : ($color === 'emerald' ? 'text-emerald-500' : ($color === 'sky' ? 'text-sky-500' : 'text-indigo-500'))) }}"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:text class="font-semibold text-zinc-900 dark:text-white">{{ $data['title'] ?? 'Notification' }}</flux:text>
                                @if($isUnread)
                                    <flux:badge color="indigo" size="sm">New</flux:badge>
                                @endif
                                @if(($data['severity'] ?? null) === 'critical')
                                    <flux:badge color="rose" size="sm">Critical</flux:badge>
                                @elseif(($data['severity'] ?? null) === 'warning')
                                    <flux:badge color="amber" size="sm">Warning</flux:badge>
                                @endif
                            </div>
                            <flux:text class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">{{ $data['message'] ?? '' }}</flux:text>
                            <flux:text class="mt-1.5 block text-[11px] uppercase tracking-wider text-zinc-400">
                                {{ $notification->created_at->format('M d, Y h:i A') }} · {{ $notification->created_at->diffForHumans() }}
                            </flux:text>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2 sm:pl-4">
                        @if(! empty($data['url']))
                            <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" href="{{ $data['url'] }}" wire:navigate>Open</flux:button>
                        @endif
                        @if($isUnread)
                            <flux:button size="xs" variant="filled" icon="check" wire:click="markAsRead('{{ $notification->id }}')" data-test="read-{{ $notification->id }}">Mark read</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-6 py-16">
                    <div class="flex flex-col items-center justify-center text-center">
                        <flux:icon name="check-circle" class="mb-4 size-12 text-emerald-500" />
                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">
                            {{ $filter === 'unread' ? 'You are all caught up' : 'No notifications yet' }}
                        </flux:heading>
                        <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $filter === 'unread'
                                ? 'Nothing unread. Switch to All to review earlier notifications.'
                                : 'Low stock alerts, stock forecasts and purchase order updates will appear here.' }}
                        </flux:text>
                    </div>
                </div>
            @endforelse
        </div>

        @if($notifications && $notifications->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
</div>
