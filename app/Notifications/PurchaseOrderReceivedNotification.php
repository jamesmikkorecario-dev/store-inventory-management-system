<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Goods have been booked in against a purchase order.
 *
 * Covers both a partial delivery and full completion; the order's own status
 * distinguishes the two, so a single notification class serves both events.
 * Sent to the user who raised the order.
 */
class PurchaseOrderReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PurchaseOrder $order,
        private readonly int $unitsReceived = 0,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{category: string, purchase_order_id: int, po_number: string, status: string, status_label: string, supplier: string, units_received: int, fully_received: bool, occurred_at: string, title: string, message: string, icon: string, color: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $fullyReceived = $this->order->status === PurchaseOrder::STATUS_RECEIVED;

        return [
            'category' => $fullyReceived ? 'purchase_order_received' : 'purchase_order_partially_received',
            'purchase_order_id' => $this->order->id,
            'po_number' => $this->order->po_number,
            'status' => $this->order->status,
            'status_label' => $this->order->statusLabel(),
            'supplier' => $this->order->supplier->name ?? 'Unassigned',
            'units_received' => $this->unitsReceived,
            'fully_received' => $fullyReceived,
            'occurred_at' => now()->toDateTimeString(),
            'title' => $fullyReceived ? 'Purchase order fully received' : 'Partial delivery received',
            'message' => $fullyReceived
                ? $this->order->po_number.' has been received in full from '.($this->order->supplier->name ?? 'Unassigned').'.'
                : $this->unitsReceived.' unit(s) booked in against '.$this->order->po_number.'; stock is still outstanding.',
            'icon' => 'truck',
            'color' => $fullyReceived ? 'emerald' : 'amber',
            'url' => route('purchase-orders.show', $this->order),
        ];
    }
}
