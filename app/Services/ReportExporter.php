<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Barryvdh\DomPDF\Facade\Pdf;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds CSV and PDF exports for the reporting module.
 *
 * The row generator is shared by both formats so an export always mirrors the
 * on screen report for the currently applied filters.
 */
class ReportExporter
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ForecastService $forecasts,
    ) {}

    /**
     * Human readable report title.
     */
    public function title(string $type): string
    {
        return (ReportService::REPORT_TYPES[$type] ?? 'Inventory').' Report';
    }

    /**
     * Column definitions for the given report.
     *
     * @return list<array{title: string, class?: string}>
     */
    public function headers(string $type): array
    {
        return match ($type) {
            'low_stock' => [
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Current Stock', 'class' => 'text-right'],
                ['title' => 'Minimum Stock', 'class' => 'text-right'],
                ['title' => 'Shortfall', 'class' => 'text-right'],
                ['title' => 'Status'],
            ],
            'dead_stock' => [
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Current Stock', 'class' => 'text-right'],
                ['title' => 'Stock Value', 'class' => 'text-right'],
                ['title' => 'Last Transaction'],
                ['title' => 'Days Since Movement', 'class' => 'text-right'],
            ],
            'transactions' => [
                ['title' => 'Date & Time'],
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Type'],
                ['title' => 'Quantity', 'class' => 'text-right'],
                ['title' => 'Operator'],
                ['title' => 'Remarks'],
            ],
            'supplier_performance' => [
                ['title' => 'Supplier'],
                ['title' => 'Contact Person'],
                ['title' => 'Status'],
                ['title' => 'Products Supplied', 'class' => 'text-right'],
                ['title' => 'Total Stock', 'class' => 'text-right'],
                ['title' => 'Low Stock Products', 'class' => 'text-right'],
                ['title' => 'Inventory Value', 'class' => 'text-right'],
            ],
            'purchase_orders' => [
                ['title' => 'PO Number'],
                ['title' => 'Supplier'],
                ['title' => 'Order Date'],
                ['title' => 'Expected Delivery'],
                ['title' => 'Status'],
                ['title' => 'Units Ordered', 'class' => 'text-right'],
                ['title' => 'Units Received', 'class' => 'text-right'],
                ['title' => 'Order Value', 'class' => 'text-right'],
            ],
            'outstanding_orders' => [
                ['title' => 'PO Number'],
                ['title' => 'Supplier'],
                ['title' => 'Expected Delivery'],
                ['title' => 'Days Late', 'class' => 'text-right'],
                ['title' => 'Status'],
                ['title' => 'Units Outstanding', 'class' => 'text-right'],
                ['title' => 'Outstanding Value', 'class' => 'text-right'],
            ],
            'forecast' => [
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Current Stock', 'class' => 'text-right'],
                ['title' => 'Minimum Stock', 'class' => 'text-right'],
                ['title' => 'Avg Daily Usage', 'class' => 'text-right'],
                ['title' => 'Days Remaining', 'class' => 'text-right'],
                ['title' => 'Forecasted Stockout'],
                ['title' => 'Suggested Reorder Qty', 'class' => 'text-right'],
            ],
            'supplier_purchases' => [
                ['title' => 'Supplier'],
                ['title' => 'Contact Person'],
                ['title' => 'Orders', 'class' => 'text-right'],
                ['title' => 'Received Orders', 'class' => 'text-right'],
                ['title' => 'Units Ordered', 'class' => 'text-right'],
                ['title' => 'Units Received', 'class' => 'text-right'],
                ['title' => 'Purchase Value', 'class' => 'text-right'],
                ['title' => 'Last Order'],
            ],
            default => [
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Cost Price', 'class' => 'text-right'],
                ['title' => 'Selling Price', 'class' => 'text-right'],
                ['title' => 'Current Stock', 'class' => 'text-right'],
                ['title' => 'Cost Value', 'class' => 'text-right'],
                ['title' => 'Retail Value', 'class' => 'text-right'],
            ],
        };
    }

    /**
     * Stream report rows one at a time so large exports stay memory safe.
     *
     * @return Generator<int, list<array{value: string, class?: string, badge?: string}>>
     */
    public function rows(string $type, ReportFilters $filters): Generator
    {
        switch ($type) {
            case 'low_stock':
                /** @var Product $product */
                foreach ($this->reports->lowStockQuery($filters)->lazy(500) as $product) {
                    $severity = $this->reports->severityFor($product);

                    yield [
                        ['value' => $product->sku, 'class' => 'font-mono'],
                        ['value' => $product->name],
                        ['value' => $product->category->name ?? 'Uncategorized'],
                        ['value' => $product->supplier->name ?? 'Unassigned'],
                        ['value' => (string) $product->current_stock, 'class' => 'text-right font-semibold'],
                        ['value' => (string) $product->minimum_stock, 'class' => 'text-right'],
                        ['value' => (string) max($product->minimum_stock - $product->current_stock, 0), 'class' => 'text-right'],
                        ['value' => $severity === 'critical' ? 'CRITICAL' : 'LOW', 'badge' => $severity === 'critical' ? 'danger' : 'warning'],
                    ];
                }

                break;

            case 'dead_stock':
                /** @var Product $product */
                foreach ($this->reports->deadStockQuery($filters)->lazy(500) as $product) {
                    $days = $this->reports->daysSinceLastMovement($product);

                    yield [
                        ['value' => $product->sku, 'class' => 'font-mono'],
                        ['value' => $product->name],
                        ['value' => $product->category->name ?? 'Uncategorized'],
                        ['value' => $product->supplier->name ?? 'Unassigned'],
                        ['value' => (string) $product->current_stock, 'class' => 'text-right'],
                        ['value' => $this->money((float) $product->current_stock * (float) $product->cost_price), 'class' => 'text-right'],
                        ['value' => $product->last_transaction_date
                            ? Carbon::parse($product->last_transaction_date)->format('Y-m-d H:i')
                            : 'Never'],
                        ['value' => $days === null ? 'Never moved' : (string) $days, 'class' => 'text-right'],
                    ];
                }

                break;

            case 'transactions':
                /** @var InventoryTransaction $transaction */
                foreach ($this->reports->transactionQuery($filters)->lazy(500) as $transaction) {
                    yield [
                        ['value' => $transaction->transaction_date->format('Y-m-d H:i')],
                        ['value' => $transaction->product->sku ?? '-', 'class' => 'font-mono'],
                        ['value' => $transaction->product->name ?? 'Deleted Product'],
                        ['value' => $transaction->product->category->name ?? 'Uncategorized'],
                        ['value' => $transaction->product->supplier->name ?? 'Unassigned'],
                        ['value' => $this->transactionTypeLabel($transaction->type), 'badge' => $this->transactionBadge($transaction->type)],
                        ['value' => $this->signedQuantity($transaction), 'class' => 'text-right font-semibold'],
                        ['value' => $transaction->user->name ?? 'System'],
                        ['value' => $transaction->remarks ?: '-'],
                    ];
                }

                break;

            case 'supplier_performance':
                /** @var Supplier $supplier */
                foreach ($this->reports->supplierPerformanceQuery($filters)->get() as $supplier) {
                    yield [
                        ['value' => $supplier->name, 'class' => 'font-semibold'],
                        ['value' => $supplier->contact_person ?: '-'],
                        ['value' => ucfirst($supplier->status), 'badge' => $supplier->status === 'active' ? 'success' : 'info'],
                        ['value' => (string) (int) $supplier->products_supplied, 'class' => 'text-right'],
                        ['value' => (string) (int) $supplier->total_units, 'class' => 'text-right'],
                        ['value' => (string) (int) $supplier->low_stock_products, 'class' => 'text-right'],
                        ['value' => $this->money((float) $supplier->total_value), 'class' => 'text-right font-medium'],
                    ];
                }

                break;

            case 'purchase_orders':
                /** @var PurchaseOrder $order */
                foreach ($this->reports->purchaseOrderQuery($filters)->lazy(500) as $order) {
                    yield [
                        ['value' => $order->po_number, 'class' => 'font-mono'],
                        ['value' => $order->supplier->name ?? 'Unassigned'],
                        ['value' => $order->order_date->format('Y-m-d')],
                        ['value' => $order->expected_delivery_date?->format('Y-m-d') ?? 'Not set'],
                        ['value' => $order->statusLabel(), 'badge' => $this->purchaseOrderBadge($order->status)],
                        ['value' => (string) (int) $order->units_ordered, 'class' => 'text-right'],
                        ['value' => (string) (int) $order->units_received, 'class' => 'text-right'],
                        ['value' => $this->money((float) $order->total_amount), 'class' => 'text-right font-medium'],
                    ];
                }

                break;

            case 'outstanding_orders':
                /** @var PurchaseOrder $order */
                foreach ($this->reports->outstandingOrderQuery($filters)->lazy(500) as $order) {
                    $daysLate = $this->daysLate($order);

                    yield [
                        ['value' => $order->po_number, 'class' => 'font-mono'],
                        ['value' => $order->supplier->name ?? 'Unassigned'],
                        ['value' => $order->expected_delivery_date?->format('Y-m-d') ?? 'Not set'],
                        ['value' => $daysLate === null ? '-' : (string) $daysLate, 'class' => 'text-right'],
                        ['value' => $order->statusLabel(), 'badge' => $this->purchaseOrderBadge($order->status)],
                        ['value' => (string) max((int) $order->units_ordered - (int) $order->units_received, 0), 'class' => 'text-right font-semibold'],
                        ['value' => $this->money((float) $order->outstanding_value), 'class' => 'text-right font-medium'],
                    ];
                }

                break;

            case 'forecast':
                /** @var Product $product */
                foreach ($this->forecasts->forecastQuery($filters)->lazy(500) as $product) {
                    $forecast = $this->forecasts->forecastFor($product, $filters->forecastDays);

                    yield [
                        ['value' => $product->sku, 'class' => 'font-mono'],
                        ['value' => $product->name],
                        ['value' => $product->category->name ?? 'Uncategorized'],
                        ['value' => $product->supplier->name ?? 'Unassigned'],
                        ['value' => (string) $product->current_stock, 'class' => 'text-right font-semibold'],
                        ['value' => (string) $product->minimum_stock, 'class' => 'text-right'],
                        ['value' => $forecast['has_data'] ? number_format($forecast['average_daily_usage'], 2) : '-', 'class' => 'text-right'],
                        ['value' => $forecast['days_remaining'] === null ? ForecastService::INSUFFICIENT_DATA : (string) (int) floor($forecast['days_remaining']), 'class' => 'text-right'],
                        ['value' => $forecast['stockout_date']?->format('Y-m-d') ?? ForecastService::INSUFFICIENT_DATA],
                        ['value' => (string) $forecast['suggested_reorder_quantity'], 'class' => 'text-right font-medium'],
                    ];
                }

                break;

            case 'supplier_purchases':
                /** @var Supplier $supplier */
                foreach ($this->reports->supplierPurchaseQuery($filters)->get() as $supplier) {
                    yield [
                        ['value' => $supplier->name, 'class' => 'font-semibold'],
                        ['value' => $supplier->contact_person ?: '-'],
                        ['value' => (string) (int) $supplier->order_count, 'class' => 'text-right'],
                        ['value' => (string) (int) $supplier->received_orders, 'class' => 'text-right'],
                        ['value' => (string) (int) $supplier->units_ordered, 'class' => 'text-right'],
                        ['value' => (string) (int) $supplier->units_received, 'class' => 'text-right'],
                        ['value' => $this->money((float) $supplier->total_value), 'class' => 'text-right font-medium'],
                        ['value' => $supplier->last_order_date
                            ? Carbon::parse($supplier->last_order_date)->format('Y-m-d')
                            : '-'],
                    ];
                }

                break;

            default:
                /** @var Product $product */
                foreach ($this->reports->valuationQuery($filters)->lazy(500) as $product) {
                    yield [
                        ['value' => $product->sku, 'class' => 'font-mono'],
                        ['value' => $product->name],
                        ['value' => $product->category->name ?? 'Uncategorized'],
                        ['value' => $product->supplier->name ?? 'Unassigned'],
                        ['value' => $this->money((float) $product->cost_price), 'class' => 'text-right'],
                        ['value' => $this->money((float) $product->selling_price), 'class' => 'text-right'],
                        ['value' => (string) $product->current_stock, 'class' => 'text-right font-semibold'],
                        ['value' => $this->money((float) $product->current_stock * (float) $product->cost_price), 'class' => 'text-right font-medium'],
                        ['value' => $this->money((float) $product->current_stock * (float) $product->selling_price), 'class' => 'text-right font-medium'],
                    ];
                }
        }
    }

    /**
     * Totals row derived from the aggregate summary (not just the visible page).
     *
     * @return list<array{value: string, class?: string}>
     */
    public function totals(string $type, ReportFilters $filters): array
    {
        switch ($type) {
            case 'low_stock':
                $summary = $this->reports->lowStockSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['total'].' products)'],
                    ['value' => 'Critical: '.$summary['critical']],
                    ['value' => 'Low: '.$summary['low']],
                    ['value' => 'Out of stock: '.$summary['out_of_stock']],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => (string) $summary['units_required'], 'class' => 'text-right'],
                    ['value' => ''],
                ];

            case 'dead_stock':
                $summary = $this->reports->deadStockSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['total'].' products)'],
                    ['value' => 'Never moved: '.$summary['never_moved']],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => (string) $summary['total_units'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['tied_value']), 'class' => 'text-right'],
                    ['value' => ''],
                    ['value' => ''],
                ];

            case 'transactions':
                $summary = $this->reports->transactionSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['transaction_count'].' transactions)'],
                    ['value' => ''],
                    ['value' => 'Stock In: '.$summary['stock_in']],
                    ['value' => 'Stock Out: '.$summary['stock_out']],
                    ['value' => 'Adjustments: '.$summary['adjustments']],
                    ['value' => 'Net'],
                    ['value' => $this->signed($summary['net_movement']), 'class' => 'text-right'],
                    ['value' => ''],
                    ['value' => ''],
                ];

            case 'supplier_performance':
                $summary = $this->reports->supplierPerformanceSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['supplier_count'].' suppliers)'],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => (string) $summary['product_count'], 'class' => 'text-right'],
                    ['value' => (string) $summary['total_units'], 'class' => 'text-right'],
                    ['value' => (string) $summary['low_stock_products'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['total_value']), 'class' => 'text-right'],
                ];

            case 'purchase_orders':
                $summary = $this->reports->purchaseOrderSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['order_count'].' orders)'],
                    ['value' => 'Open: '.$summary['open_orders']],
                    ['value' => 'Received: '.$summary['received_orders']],
                    ['value' => 'Cancelled: '.$summary['cancelled_orders']],
                    ['value' => 'Overdue: '.$summary['overdue_orders']],
                    ['value' => (string) $summary['units_ordered'], 'class' => 'text-right'],
                    ['value' => (string) $summary['units_received'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['total_value']), 'class' => 'text-right'],
                ];

            case 'outstanding_orders':
                $summary = $this->reports->outstandingOrderSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['order_count'].' orders)'],
                    ['value' => ''],
                    ['value' => 'Due within 7 days: '.$summary['due_within_week']],
                    ['value' => 'Overdue: '.$summary['overdue_orders'], 'class' => 'text-right'],
                    ['value' => ''],
                    ['value' => (string) $summary['units_outstanding'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['outstanding_value']), 'class' => 'text-right'],
                ];

            case 'forecast':
                $summary = $this->forecasts->forecastSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['product_count'].' products)'],
                    ['value' => 'At risk: '.$summary['at_risk']],
                    ['value' => 'Critical: '.$summary['critical']],
                    ['value' => 'Out of stock: '.$summary['out_of_stock']],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => number_format($summary['total_daily_usage'], 2), 'class' => 'text-right'],
                    ['value' => ForecastService::INSUFFICIENT_DATA.': '.$summary['insufficient_data'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['suggested_value']).' at cost'],
                    ['value' => (string) $summary['suggested_units'], 'class' => 'text-right'],
                ];

            case 'supplier_purchases':
                $summary = $this->reports->supplierPurchaseSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['supplier_count'].' suppliers)'],
                    ['value' => ''],
                    ['value' => (string) $summary['order_count'], 'class' => 'text-right'],
                    ['value' => ''],
                    ['value' => (string) $summary['units_ordered'], 'class' => 'text-right'],
                    ['value' => (string) $summary['units_received'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['total_value']), 'class' => 'text-right'],
                    ['value' => ''],
                ];

            default:
                $summary = $this->reports->valuationSummary($filters);

                return [
                    ['value' => 'TOTALS ('.$summary['product_count'].' products)'],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => ''],
                    ['value' => (string) $summary['total_units'], 'class' => 'text-right'],
                    ['value' => $this->money($summary['total_cost_value']), 'class' => 'text-right'],
                    ['value' => $this->money($summary['total_retail_value']), 'class' => 'text-right'],
                ];
        }
    }

    /**
     * Stream a UTF-8 (Excel compatible) CSV export of the filtered report.
     */
    public function csv(string $type, ReportFilters $filters): StreamedResponse
    {
        $filename = $this->filename($type, 'csv');
        $headers = array_map(fn (array $header): string => $header['title'], $this->headers($type));
        $rows = $this->rows($type, $filters);
        $totals = array_map(fn (array $total): string => $total['value'], $this->totals($type, $filters));

        return response()->streamDownload(function () use ($headers, $rows, $totals): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // UTF-8 BOM so Excel opens the file with the correct encoding.
            fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn (array $cell): string => html_entity_decode(strip_tags($cell['value'])),
                    $row
                ));
            }

            fputcsv($handle, []);
            fputcsv($handle, $totals);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Stream a PDF export of the filtered report.
     */
    public function pdf(string $type, ReportFilters $filters, string $operator, int $maxRows = 1000): StreamedResponse
    {
        $rows = [];
        $truncated = false;

        foreach ($this->rows($type, $filters) as $row) {
            if (count($rows) >= $maxRows) {
                $truncated = true;
                break;
            }

            $rows[] = $row;
        }

        $pdf = Pdf::loadView('exports.pdf_report', [
            'title' => $this->title($type),
            'operator' => $operator,
            'dateRange' => $this->dateRangeLabel($type, $filters),
            'filters' => $this->filterSummary($filters),
            'headers' => $this->headers($type),
            'rows' => $rows,
            'totals' => $this->totals($type, $filters),
            'note' => $truncated
                ? 'Row output limited to the first '.$maxRows.' records; totals reflect the full filtered dataset. Use the CSV export for the complete listing.'
                : null,
        ]);

        $filename = $this->filename($type, 'pdf');

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Export filename including the report type and a timestamp.
     */
    public function filename(string $type, string $extension): string
    {
        return 'sims_'.$type.'_report_'.now()->format('Ymd_His').'.'.$extension;
    }

    /**
     * Readable description of the filters applied to the export.
     */
    public function filterSummary(ReportFilters $filters): string
    {
        $applied = [];

        if ($filters->search) {
            $applied[] = 'Search: "'.$filters->search.'"';
        }

        if ($filters->categoryId) {
            $applied[] = 'Category: '.$this->lookupName(Category::query(), $filters->categoryId);
        }

        if ($filters->supplierId) {
            $applied[] = 'Supplier: '.$this->lookupName(Supplier::query(), $filters->supplierId);
        }

        if ($filters->severity) {
            $applied[] = 'Severity: '.ucfirst($filters->severity);
        }

        if ($filters->transactionType) {
            $applied[] = 'Type: '.$this->transactionTypeLabel($filters->transactionType);
        }

        if ($filters->productId) {
            $applied[] = 'Product: '.$this->lookupName(Product::query(), $filters->productId);
        }

        if ($filters->purchaseOrderStatus !== null) {
            $applied[] = 'PO Status: '.(PurchaseOrder::STATUSES[$filters->purchaseOrderStatus] ?? $filters->purchaseOrderStatus);
        }

        return $applied === [] ? 'None' : implode(', ', $applied);
    }

    /**
     * Resolve a record name for the filter summary, falling back to the raw id.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function lookupName(Builder $query, int $id): string
    {
        $name = $query->whereKey($id)->value('name');

        return is_string($name) ? $name : (string) $id;
    }

    /**
     * Date range / period label shown in the PDF header.
     */
    private function dateRangeLabel(string $type, ReportFilters $filters): string
    {
        if ($type === 'dead_stock') {
            return 'No movement in the last '.$filters->inactivityDays.' days';
        }

        if ($type === 'forecast') {
            return 'Projected from the last '.$filters->forecastDays.' days of consumption';
        }

        if ($type === 'outstanding_orders' && $filters->startDate === null && $filters->endDate === null) {
            return 'All open orders with undelivered units';
        }

        if ($filters->startDate === null && $filters->endDate === null) {
            return 'All time';
        }

        return ($filters->startDate ?? 'Beginning').' to '.($filters->endDate ?? 'Now');
    }

    private function transactionTypeLabel(string $type): string
    {
        return strtoupper(str_replace('_', ' ', $type));
    }

    /**
     * PDF badge variant matching a purchase order status.
     */
    private function purchaseOrderBadge(string $status): string
    {
        return match ($status) {
            PurchaseOrder::STATUS_RECEIVED => 'success',
            PurchaseOrder::STATUS_CANCELLED => 'danger',
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'warning',
            default => 'info',
        };
    }

    /**
     * Days past the expected delivery date, or null when not late / not scheduled.
     */
    private function daysLate(PurchaseOrder $order): ?int
    {
        if ($order->expected_delivery_date === null) {
            return null;
        }

        $expected = $order->expected_delivery_date->startOfDay();
        $today = Carbon::now()->startOfDay();

        if (! $expected->isBefore($today)) {
            return null;
        }

        return (int) $expected->diffInDays($today);
    }

    private function transactionBadge(string $type): string
    {
        return match ($type) {
            'stock_in' => 'success',
            'stock_out' => 'danger',
            default => 'info',
        };
    }

    private function signedQuantity(InventoryTransaction $transaction): string
    {
        return match ($transaction->type) {
            'stock_in' => '+'.abs($transaction->quantity),
            'stock_out' => '-'.abs($transaction->quantity),
            default => $this->signed($transaction->quantity),
        };
    }

    private function signed(int $value): string
    {
        return $value > 0 ? '+'.$value : (string) $value;
    }

    private function money(float $value): string
    {
        return '$'.number_format($value, 2);
    }
}
