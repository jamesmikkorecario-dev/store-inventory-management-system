<?php

namespace App\Livewire;

use App\Services\LowStockNotificationService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class SidebarAlertsBadge extends Component
{
    public int $unreadAlertsCount = 0;

    public function mount(LowStockNotificationService $service): void
    {
        $this->unreadAlertsCount = $service->getUnreadCount();
    }

    #[On('alerts-updated')]
    public function refreshBadge(LowStockNotificationService $service): void
    {
        $this->unreadAlertsCount = $service->getUnreadCount();
    }

    public function render(): View
    {
        return view('livewire.sidebar-alerts-badge');
    }
}
