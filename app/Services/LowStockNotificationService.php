<?php

namespace App\Services;

use App\Models\LowStockNotification;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class LowStockNotificationService
{
    /**
     * Handle stock updates for a product and manage low stock notifications.
     */
    public function handleProductStockUpdate(Product $product): void
    {
        $activeNotification = LowStockNotification::where('product_id', $product->id)
            ->whereNull('resolved_at')
            ->first();

        if ($product->trashed() || $product->current_stock > $product->minimum_stock) {
            // Stock is healthy or product is deleted. Resolve active notification if it exists.
            if ($activeNotification) {
                $activeNotification->update(['resolved_at' => now()]);
            }

            return;
        }

        // Stock is low or critical
        $severity = $product->current_stock <= 0 ? 'critical' : 'low';

        if ($activeNotification) {
            // Update existing active notification
            $activeNotification->update([
                'current_stock' => $product->current_stock,
                'threshold' => $product->minimum_stock,
                'severity' => $severity,
            ]);
        } else {
            // Create a new notification
            LowStockNotification::create([
                'product_id' => $product->id,
                'current_stock' => $product->current_stock,
                'threshold' => $product->minimum_stock,
                'severity' => $severity,
            ]);
        }
    }

    /**
     * Get unread notifications for the header dropdown.
     *
     * @return Collection<int, LowStockNotification>
     */
    public function getUnreadNotifications(): Collection
    {
        return LowStockNotification::with('product')
            ->whereNull('resolved_at')
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get alerts with filtering and pagination.
     *
     * @return LengthAwarePaginator<int, LowStockNotification>
     */
    public function getAlerts(string $filter = 'all')
    {
        $query = LowStockNotification::with('product')
            ->orderByDesc('created_at');

        return (match ($filter) {
            'unread' => $query->whereNull('read_at'),
            'read' => $query->whereNotNull('read_at'),
            'active' => $query->whereNull('resolved_at'),
            'resolved' => $query->whereNotNull('resolved_at'),
            'critical' => $query->where('severity', 'critical'),
            'low' => $query->where('severity', 'low'),
            default => $query,
        })->paginate(15);
    }

    /**
     * Get the latest active alerts for the dashboard widget.
     *
     * @return Collection<int, LowStockNotification>
     */
    public function getLatestActiveAlerts(int $limit = 5)
    {
        return LowStockNotification::with('product')
            ->whereNull('resolved_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(int $id): void
    {
        LowStockNotification::where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Mark all active notifications as read.
     */
    public function markAllAsRead(): void
    {
        LowStockNotification::whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Get the count of unread notifications.
     */
    public function getUnreadCount(): int
    {
        return LowStockNotification::whereNull('resolved_at')
            ->whereNull('read_at')
            ->count();
    }
}
