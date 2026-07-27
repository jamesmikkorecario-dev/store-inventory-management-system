<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Header notification bell: unread count plus a dropdown of the latest items.
 *
 * Only ever reads the authenticated user's own notifications, through the
 * `notifications` morph relation, so cross-user access is impossible by design.
 */
class NotificationBell extends Component
{
    public int $unreadCount = 0;

    public bool $unreadOnly = true;

    public function mount(): void
    {
        $this->refreshCount();
    }

    #[On('notifications-updated')]
    #[On('alerts-updated')]
    #[On('purchase-orders-updated')]
    public function refreshCount(): void
    {
        $user = $this->user();

        $this->unreadCount = $user === null ? 0 : app(NotificationService::class)->unreadCountFor($user);
    }

    public function toggleUnreadOnly(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;
    }

    public function markAsRead(string $notificationId): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        app(NotificationService::class)->markAsRead($user, $notificationId);

        $this->refreshCount();
        $this->dispatch('notifications-updated');
    }

    public function markAllAsRead(): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        app(NotificationService::class)->markAllAsRead($user);

        $this->refreshCount();
        $this->dispatch('notifications-updated');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.notification-bell', [
            'notifications' => $user === null
                ? collect()
                : app(NotificationService::class)->recentFor($user, 8, $this->unreadOnly),
        ]);
    }

    private function user(): ?User
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user;
    }
}
