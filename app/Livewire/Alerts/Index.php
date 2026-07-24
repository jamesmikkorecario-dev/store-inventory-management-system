<?php

namespace App\Livewire\Alerts;

use App\Models\LowStockNotification;
use App\Services\LowStockNotificationService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $readStatus = 'all';

    #[Url]
    public string $severity = 'all';

    public function mount(): void
    {
        // View authorization handled by route middleware
    }

    public function markAsRead(int $id, LowStockNotificationService $service): void
    {
        $service->markAsRead($id);
        $this->dispatch('alerts-updated');
        $this->dispatch('dashboard-updated');
    }

    public function markAllAsRead(LowStockNotificationService $service): void
    {
        $service->markAllAsRead();
        $this->dispatch('alerts-updated');
        $this->dispatch('dashboard-updated');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedReadStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    #[On('alerts-updated')]
    public function refreshData(): void
    {
        // Component will re-render
    }

    public function render(): View
    {
        $query = LowStockNotification::with('product')
            ->when($this->search !== '', function ($q) {
                $q->whereHas('product', function ($q2) {
                    $q2->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('sku', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->status === 'active', fn ($q) => $q->whereNull('resolved_at'))
            ->when($this->status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($this->readStatus === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($this->readStatus === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($this->severity === 'critical', fn ($q) => $q->where('severity', 'critical'))
            ->when($this->severity === 'low', fn ($q) => $q->where('severity', 'low'))
            ->orderByDesc('created_at');

        return view('livewire.alerts.index', [
            'alerts' => $query->paginate(15),
            'activeCount' => LowStockNotification::whereNull('resolved_at')->count(),
            'criticalCount' => LowStockNotification::whereNull('resolved_at')->where('severity', 'critical')->count(),
            'lowStockCount' => LowStockNotification::whereNull('resolved_at')->where('severity', 'low')->count(),
            'unreadCount' => LowStockNotification::whereNull('read_at')->count(),
        ]);
    }
}
