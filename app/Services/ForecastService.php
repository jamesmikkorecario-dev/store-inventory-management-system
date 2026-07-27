<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consumption based inventory forecasting.
 *
 * Usage is derived exclusively from `stock_out` inventory transactions inside the
 * selected analysis window, so stock intake (purchase order receipts, adjustments)
 * never inflates projected demand. Every figure is produced from a single joined
 * aggregate subquery, which keeps the query count constant regardless of catalogue
 * size — the per row helpers below are pure arithmetic over columns that are
 * already selected, so they never trigger additional queries.
 */
class ForecastService
{
    /**
     * Analysis windows offered by the dashboard widget (days).
     *
     * @var list<int>
     */
    public const ANALYSIS_PERIODS = [7, 30, 90];

    /**
     * Analysis windows offered by the forecast report (days).
     *
     * @var list<int>
     */
    public const REPORT_PERIODS = [30, 60, 90];

    /**
     * Default analysis window when none is supplied (days).
     */
    public const DEFAULT_PERIOD = 30;

    /**
     * Days of demand a reorder should cover.
     */
    public const COVERAGE_DAYS = 30;

    /**
     * Buffer days held on top of the coverage window.
     */
    public const SAFETY_STOCK_DAYS = 7;

    /**
     * Assumed supplier lead time, used to derive the suggested reorder date.
     */
    public const LEAD_TIME_DAYS = 7;

    /**
     * Days remaining at or below which a product is treated as critical.
     */
    public const CRITICAL_DAYS = 7;

    /**
     * Days remaining at or below which a product is treated as at risk.
     */
    public const WARNING_DAYS = 30;

    /**
     * Label shown when a product has no consumption history to forecast from.
     */
    public const INSUFFICIENT_DATA = 'Insufficient Data';

    /**
     * Products with their consumption aggregates for the selected window.
     *
     * Ordered by nearest projected depletion; products without usage history sort
     * last because they cannot be dated.
     *
     * @return Builder<Product>
     */
    public function forecastQuery(ReportFilters $filters): Builder
    {
        $days = $this->periodDays($filters->forecastDays);

        $query = Product::query()
            ->with(['category', 'supplier'])
            ->leftJoinSub($this->usageSubQuery($days), 'usage', 'usage.product_id', '=', 'products.id')
            ->select('products.*')
            ->selectRaw('COALESCE(usage.usage_units, 0) as usage_units')
            ->selectRaw('COALESCE(usage.usage_events, 0) as usage_events')
            ->addSelect('usage.last_movement_at')
            ->selectRaw('(COALESCE(usage.usage_units, 0) * 1.0 / ?) as avg_daily_usage', [$days])
            ->selectRaw(
                'CASE WHEN COALESCE(usage.usage_units, 0) > 0 THEN (products.current_stock * 1.0 * ? / usage.usage_units) ELSE NULL END as days_remaining',
                [$days]
            );

        $this->applyFilters($query, $filters);

        return $query
            ->orderByRaw('CASE WHEN COALESCE(usage.usage_units, 0) > 0 THEN 0 ELSE 1 END')
            ->orderByRaw(
                'CASE WHEN COALESCE(usage.usage_units, 0) > 0 THEN (products.current_stock * 1.0 * ? / usage.usage_units) ELSE NULL END',
                [$days]
            )
            ->orderBy('products.name');
    }

    /**
     * Headline forecast figures for the filtered product set.
     *
     * @return array{product_count: int, forecastable: int, insufficient_data: int, out_of_stock: int, critical: int, at_risk: int, suggested_units: int, suggested_value: float, total_daily_usage: float}
     */
    public function forecastSummary(ReportFilters $filters): array
    {
        $days = $this->periodDays($filters->forecastDays);

        $query = Product::query()
            ->leftJoinSub($this->usageSubQuery($days), 'usage', 'usage.product_id', '=', 'products.id')
            ->select([
                'products.current_stock',
                'products.minimum_stock',
                'products.cost_price',
            ])
            ->selectRaw('COALESCE(usage.usage_units, 0) as usage_units');

        $this->applyFilters($query, $filters);

        $summary = [
            'product_count' => 0,
            'forecastable' => 0,
            'insufficient_data' => 0,
            'out_of_stock' => 0,
            'critical' => 0,
            'at_risk' => 0,
            'suggested_units' => 0,
            'suggested_value' => 0.0,
            'total_daily_usage' => 0.0,
        ];

        /** @var array<string, mixed> $row */
        foreach ($query->toBase()->cursor() as $row) {
            $values = (array) $row;

            $currentStock = (int) ($values['current_stock'] ?? 0);
            $minimumStock = (int) ($values['minimum_stock'] ?? 0);
            $costPrice = (float) ($values['cost_price'] ?? 0);
            $usageUnits = (int) ($values['usage_units'] ?? 0);

            $averageDailyUsage = $this->averageDailyUsage($usageUnits, $days);
            $daysRemaining = $this->daysRemaining($currentStock, $averageDailyUsage);
            $suggested = $this->suggestedReorderQuantity($currentStock, $minimumStock, $averageDailyUsage);

            $summary['product_count']++;
            $summary['total_daily_usage'] += $averageDailyUsage;
            $summary['suggested_units'] += $suggested;
            $summary['suggested_value'] += $suggested * $costPrice;

            if ($averageDailyUsage > 0.0) {
                $summary['forecastable']++;
            } else {
                $summary['insufficient_data']++;
            }

            if ($currentStock === 0) {
                $summary['out_of_stock']++;
            }

            if ($daysRemaining !== null && $daysRemaining <= self::CRITICAL_DAYS) {
                $summary['critical']++;
            }

            if ($daysRemaining !== null && $daysRemaining <= self::WARNING_DAYS) {
                $summary['at_risk']++;
            }
        }

        $summary['suggested_value'] = round($summary['suggested_value'], 2);
        $summary['total_daily_usage'] = round($summary['total_daily_usage'], 2);

        return $summary;
    }

    /**
     * Full forecast for a product row produced by {@see forecastQuery()}.
     *
     * Falls back to an on demand usage lookup when the row was not loaded through
     * the forecast query, so the helper is safe to call from anywhere.
     *
     * @return array{has_data: bool, period_days: int, usage_units: int, average_daily_usage: float, average_weekly_usage: float, average_monthly_usage: float, days_remaining: float|null, days_remaining_label: string, stockout_date: Carbon|null, suggested_reorder_quantity: int, suggested_reorder_date: Carbon|null, severity: string, severity_label: string}
     */
    public function forecastFor(Product $product, ?int $periodDays = null): array
    {
        $days = $this->periodDays($periodDays);

        $usageUnits = $product->usage_units !== null
            ? (int) $product->usage_units
            : $this->usageUnitsFor($product, $days);

        $averageDailyUsage = $this->averageDailyUsage($usageUnits, $days);
        $daysRemaining = $this->daysRemaining($product->current_stock, $averageDailyUsage);
        $stockoutDate = $daysRemaining === null ? null : Carbon::now()->startOfDay()->addDays((int) floor($daysRemaining));

        $suggestedQuantity = $this->suggestedReorderQuantity(
            $product->current_stock,
            $product->minimum_stock,
            $averageDailyUsage,
        );

        $severity = $this->severityFor($product->current_stock, $daysRemaining, $averageDailyUsage);

        return [
            'has_data' => $averageDailyUsage > 0.0,
            'period_days' => $days,
            'usage_units' => $usageUnits,
            'average_daily_usage' => $averageDailyUsage,
            'average_weekly_usage' => round($averageDailyUsage * 7, 2),
            'average_monthly_usage' => round($averageDailyUsage * 30, 2),
            'days_remaining' => $daysRemaining,
            'days_remaining_label' => $daysRemaining === null
                ? self::INSUFFICIENT_DATA
                : number_format(floor($daysRemaining)).' days',
            'stockout_date' => $stockoutDate,
            'suggested_reorder_quantity' => $suggestedQuantity,
            'suggested_reorder_date' => $stockoutDate?->copy()->subDays(self::LEAD_TIME_DAYS),
            'severity' => $severity,
            'severity_label' => $this->severityLabel($severity),
        ];
    }

    /**
     * Products projected to run out soonest, for the dashboard widget.
     *
     * @return EloquentCollection<int, Product>
     */
    public function upcomingStockouts(int $limit = 5, ?int $periodDays = null, ?int $supplierId = null): EloquentCollection
    {
        $filters = new ReportFilters(
            supplierId: $supplierId,
            forecastDays: $this->periodDays($periodDays),
        );

        return $this->forecastQuery($filters)
            ->whereRaw('COALESCE(usage.usage_units, 0) > 0')
            ->limit($limit)
            ->get();
    }

    /**
     * Products that need replenishing, newest risk first.
     *
     * A product qualifies when a reorder quantity is suggested and it is either
     * already at or below its minimum stock, or projected to deplete inside the
     * warning window.
     *
     * @return EloquentCollection<int, Product>
     */
    public function replenishmentRecommendations(?int $periodDays = null, int $limit = 25, ?int $supplierId = null): EloquentCollection
    {
        $days = $this->periodDays($periodDays);

        $filters = new ReportFilters(supplierId: $supplierId, forecastDays: $days);

        $candidates = $this->forecastQuery($filters)
            ->where(function (Builder $query) use ($days) {
                $query->whereColumn('products.current_stock', '<=', 'products.minimum_stock')
                    ->orWhereRaw(
                        'COALESCE(usage.usage_units, 0) > 0 AND (products.current_stock * 1.0 * ? / usage.usage_units) <= ?',
                        [$days, self::WARNING_DAYS]
                    );
            })
            ->where('products.status', 'active')
            ->limit($limit * 2)
            ->get();

        return $candidates
            ->filter(fn (Product $product): bool => $this->forecastFor($product, $days)['suggested_reorder_quantity'] > 0)
            ->take($limit)
            ->values();
    }

    /**
     * Suggested reorder quantity: enough to cover the coverage window plus safety
     * stock, never below the product's own minimum stock, and never negative.
     */
    public function suggestedReorderQuantity(int $currentStock, int $minimumStock, float $averageDailyUsage): int
    {
        $projectedDemand = (int) ceil($averageDailyUsage * (self::COVERAGE_DAYS + self::SAFETY_STOCK_DAYS));

        $target = max($projectedDemand, $minimumStock);

        return max(0, $target - $currentStock);
    }

    /**
     * Average units consumed per day across the analysis window.
     */
    public function averageDailyUsage(int $usageUnits, int $periodDays): float
    {
        if ($usageUnits <= 0 || $periodDays <= 0) {
            return 0.0;
        }

        return round($usageUnits / $periodDays, 4);
    }

    /**
     * Days of cover remaining, or null when consumption cannot be projected.
     */
    public function daysRemaining(int $currentStock, float $averageDailyUsage): ?float
    {
        if ($averageDailyUsage <= 0.0) {
            return null;
        }

        return round($currentStock / $averageDailyUsage, 2);
    }

    /**
     * Risk bucket for a product's projection.
     */
    public function severityFor(int $currentStock, ?float $daysRemaining, float $averageDailyUsage): string
    {
        if ($currentStock === 0) {
            return 'out_of_stock';
        }

        if ($averageDailyUsage <= 0.0 || $daysRemaining === null) {
            return 'unknown';
        }

        if ($daysRemaining <= self::CRITICAL_DAYS) {
            return 'critical';
        }

        if ($daysRemaining <= self::WARNING_DAYS) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Human readable severity label.
     */
    public function severityLabel(string $severity): string
    {
        return match ($severity) {
            'out_of_stock' => 'Out of Stock',
            'critical' => 'Critical',
            'warning' => 'At Risk',
            'healthy' => 'Healthy',
            default => self::INSUFFICIENT_DATA,
        };
    }

    /**
     * Flux badge colour matching a severity bucket.
     */
    public function severityColor(string $severity): string
    {
        return match ($severity) {
            'out_of_stock', 'critical' => 'rose',
            'warning' => 'amber',
            'healthy' => 'emerald',
            default => 'zinc',
        };
    }

    /**
     * Normalise a requested analysis window to a supported value.
     */
    public function periodDays(?int $requested): int
    {
        $supported = array_unique([...self::ANALYSIS_PERIODS, ...self::REPORT_PERIODS]);

        return in_array($requested, $supported, true) ? (int) $requested : self::DEFAULT_PERIOD;
    }

    /**
     * Consumption aggregates per product for the analysis window.
     */
    private function usageSubQuery(int $days): \Illuminate\Database\Query\Builder
    {
        $cutoff = Carbon::now()->subDays($days)->startOfDay();

        return DB::table('inventory_transactions')
            ->select('product_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_out' THEN quantity ELSE 0 END), 0) as usage_units")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stock_out' THEN 1 ELSE 0 END), 0) as usage_events")
            ->selectRaw("MAX(CASE WHEN type = 'stock_out' THEN transaction_date ELSE NULL END) as last_movement_at")
            ->where('transaction_date', '>=', $cutoff)
            ->groupBy('product_id');
    }

    /**
     * Fallback usage lookup for products not loaded via the forecast query.
     */
    private function usageUnitsFor(Product $product, int $days): int
    {
        return (int) $product->inventoryTransactions()
            ->where('type', 'stock_out')
            ->where('transaction_date', '>=', Carbon::now()->subDays($days)->startOfDay())
            ->sum('quantity');
    }

    /**
     * Apply the shared product oriented report filters.
     *
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, ReportFilters $filters): void
    {
        if ($filters->categoryId) {
            $query->where('products.category_id', $filters->categoryId);
        }

        if ($filters->supplierId) {
            $query->where('products.supplier_id', $filters->supplierId);
        }

        if ($filters->productId) {
            $query->whereKey($filters->productId);
        }

        if ($filters->search) {
            $term = '%'.$filters->search.'%';
            $query->where(function (Builder $searchQuery) use ($term) {
                $searchQuery->where('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term)
                    ->orWhere('products.identifier', 'like', $term);
            });
        }
    }
}
