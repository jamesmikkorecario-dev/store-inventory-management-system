<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A purchase order has been submitted and is waiting for approval.
 *
 * Sent to every user holding the `approve purchase orders` permission.
 */
class PurchaseOrderSubmittedNotification extends Notification
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
            'category' => 'purchase_order_submitted',
            'purchase_order_id' => $this->order->id,
            'po_number' => $this->order->po_number,
            'status' => $this->order->status,
            'status_label' => $this->order->statusLabel(),
            'supplier' => $this->order->supplier->name ?? 'Unassigned',
            'occurred_at' => now()->toDateTimeString(),
            'title' => 'Purchase order awaiting approval',
            'message' => $this->order->po_number.' requires approval ('.($this->order->supplier->name ?? 'Unassigned').', $'.number_format((float) $this->order->total_amount, 2).').',
            'icon' => 'paper-airplane',
            'color' => 'sky',
            'url' => route('purchase-orders.show', $this->order),
        ];
    }
}
