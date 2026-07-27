<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A purchase order has been approved. Sent to the user who raised it.
 */
class PurchaseOrderApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly PurchaseOrder $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{category: string, purchase_order_id: int, po_number: string, status: string, status_label: string, supplier: string, occurred_at: string, title: string, message: string, icon: string, color: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'purchase_order_approved',
            'purchase_order_id' => $this->order->id,
            'po_number' => $this->order->po_number,
            'status' => $this->order->status,
            'status_label' => $this->order->statusLabel(),
            'supplier' => $this->order->supplier->name ?? 'Unassigned',
            'occurred_at' => now()->toDateTimeString(),
            'title' => 'Purchase order approved',
            'message' => $this->order->po_number.' was approved and is ready to receive from '.($this->order->supplier->name ?? 'Unassigned').'.',
            'icon' => 'check-badge',
            'color' => 'indigo',
            'url' => route('purchase-orders.show', $this->order),
        ];
    }
}
