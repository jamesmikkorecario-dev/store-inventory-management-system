<?php

namespace App\Notifications;

use App\Models\Product;
use App\Services\ForecastService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A product is forecast to run out of stock inside the warning window.
 *
 * Figures come from {@see ForecastService} and are frozen into the
 * payload, so the notification records what was projected at the time it fired.
 */
class ForecastStockoutNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Product $product,
        private readonly int $daysUntilStockout,
        private readonly int $suggestedReorderQuantity,
        private readonly int $forecastPeriodDays,
        private readonly string $severity,
        private readonly ?string $stockoutDate = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{category: string, product_id: int, product_name: string, sku: string, days_until_stockout: int, suggested_reorder_quantity: int, forecast_period_days: int, stockout_date: string|null, severity: string, title: string, message: string, icon: string, color: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'forecast_stockout',
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'days_until_stockout' => $this->daysUntilStockout,
            'suggested_reorder_quantity' => $this->suggestedReorderQuantity,
            'forecast_period_days' => $this->forecastPeriodDays,
            'stockout_date' => $this->stockoutDate,
            'severity' => $this->severity,
            'title' => $this->severity === 'critical' ? 'Imminent stockout forecast' : 'Stockout forecast',
            'message' => $this->product->name.' is projected to run out in '.$this->daysUntilStockout
                .' day(s). Suggested reorder: '.$this->suggestedReorderQuantity
                .' units (based on the last '.$this->forecastPeriodDays.' days of usage).',
            'icon' => 'chart-bar-square',
            'color' => $this->severity === 'critical' ? 'rose' : 'amber',
            'url' => route('reports.index', ['report' => 'forecast']),
        ];
    }
}
