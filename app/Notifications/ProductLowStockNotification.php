<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A product has fallen to or below its minimum stock level.
 *
 * Stored on the database channel only. The payload is denormalised on purpose so
 * the notification centre can render a row without loading the product, which
 * keeps the listing free of N+1 queries even when a product is later deleted.
 */
class ProductLowStockNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Product $product,
        private readonly string $severity,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{category: string, product_id: int, product_name: string, sku: string, current_stock: int, minimum_stock: int, severity: string, title: string, message: string, icon: string, color: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'low_stock',
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'current_stock' => $this->product->current_stock,
            'minimum_stock' => $this->product->minimum_stock,
            'severity' => $this->severity,
            'title' => $this->severity === 'critical' ? 'Critical stock level' : 'Low stock warning',
            'message' => $this->product->name.' ('.$this->product->sku.') is down to '
                .$this->product->current_stock.' units against a minimum of '.$this->product->minimum_stock.'.',
            'icon' => 'exclamation-triangle',
            'color' => $this->severity === 'critical' ? 'rose' : 'amber',
            'url' => route('products.index'),
        ];
    }
}
