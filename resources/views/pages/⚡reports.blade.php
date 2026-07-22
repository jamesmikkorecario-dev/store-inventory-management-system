<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\InventoryTransaction;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Flux\Flux;

new #[Title('Inventory Reports')] class extends Component {
    public string $reportType = 'summary'; // summary, movements, low_stock, supplier
    public string $startDate = '';
    public string $endDate = '';
    public string $categoryId = '';
    public string $supplierId = '';

    public function mount(): void
    {
        if (!Auth::user()->can('view reports')) {
            abort(403, 'Unauthorized.');
        }

        $this->startDate = now()->subDays(30)->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function getReportDataProperty(): array
    {
        $headers = [];
        $rows = [];
        $totals = [];

        if ($this->reportType === 'summary') {
            $headers = [
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Cost Price', 'class' => 'text-right'],
                ['title' => 'Selling Price', 'class' => 'text-right'],
                ['title' => 'Current Stock', 'class' => 'text-right'],
                ['title' => 'Cost Value', 'class' => 'text-right'],
                ['title' => 'Retail Value', 'class' => 'text-right'],
            ];

            $query = Product::with(['category', 'supplier']);
            if ($this->categoryId) $query->where('category_id', $this->categoryId);
            if ($this->supplierId) $query->where('supplier_id', $this->supplierId);
            $products = $query->orderBy('name')->get();

            $totalQty = 0;
            $totalCostVal = 0.0;
            $totalRetailVal = 0.0;

            foreach ($products as $p) {
                $costVal = $p->current_stock * $p->cost_price;
                $retailVal = $p->current_stock * $p->selling_price;
                
                $totalQty += $p->current_stock;
                $totalCostVal += $costVal;
                $totalRetailVal += $retailVal;

                $rows[] = [
                    ['value' => $p->sku, 'class' => 'font-mono'],
                    ['value' => $p->name],
                    ['value' => $p->category->name ?? 'Uncategorized'],
                    ['value' => $p->supplier->name ?? 'No Supplier'],
                    ['value' => '$' . number_format($p->cost_price, 2), 'class' => 'text-right'],
                    ['value' => '$' . number_format($p->selling_price, 2), 'class' => 'text-right'],
                    ['value' => $p->current_stock, 'class' => 'text-right font-semibold'],
                    ['value' => '$' . number_format($costVal, 2), 'class' => 'text-right font-medium'],
                    ['value' => '$' . number_format($retailVal, 2), 'class' => 'text-right font-medium'],
                ];
            }

            $totals = [
                ['value' => 'TOTALS'],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => $totalQty, 'class' => 'text-right'],
                ['value' => '$' . number_format($totalCostVal, 2), 'class' => 'text-right'],
                ['value' => '$' . number_format($totalRetailVal, 2), 'class' => 'text-right'],
            ];

        } elseif ($this->reportType === 'movements') {
            $headers = [
                ['title' => 'Date & Time'],
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Type'],
                ['title' => 'Quantity', 'class' => 'text-right'],
                ['title' => 'Operator'],
                ['title' => 'Remarks'],
            ];

            $query = InventoryTransaction::with(['product.category', 'product.supplier', 'user']);
            
            if ($this->startDate) $query->whereDate('transaction_date', '>=', $this->startDate);
            if ($this->endDate) $query->whereDate('transaction_date', '<=', $this->endDate);
            
            if ($this->categoryId) {
                $query->whereHas('product', function ($q) {
                    $q->where('category_id', $this->categoryId);
                });
            }
            if ($this->supplierId) {
                $query->whereHas('product', function ($q) {
                    $q->where('supplier_id', $this->supplierId);
                });
            }

            $txs = $query->orderBy('transaction_date', 'desc')->get();

            foreach ($txs as $tx) {
                $badge = 'info';
                if ($tx->type === 'stock_in') $badge = 'success';
                if ($tx->type === 'stock_out') $badge = 'danger';

                $rows[] = [
                    ['value' => $tx->transaction_date->format('Y-m-d H:i')],
                    ['value' => $tx->product->sku ?? '-', 'class' => 'font-mono'],
                    ['value' => $tx->product->name ?? 'Deleted Product'],
                    ['value' => strtoupper(str_replace('_', ' ', $tx->type)), 'badge' => $badge],
                    ['value' => ($tx->type === 'stock_in' ? '+' : ($tx->type === 'stock_out' ? '-' : '')) . abs($tx->quantity), 'class' => 'text-right font-semibold'],
                    ['value' => $tx->user->name ?? 'System'],
                    ['value' => $tx->remarks ?? '-'],
                ];
            }

            $totals = [
                ['value' => 'Total records: ' . count($rows)],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
            ];

        } elseif ($this->reportType === 'low_stock') {
            $headers = [
                ['title' => 'SKU'],
                ['title' => 'Product Name'],
                ['title' => 'Category'],
                ['title' => 'Supplier'],
                ['title' => 'Min Stock Threshold', 'class' => 'text-right'],
                ['title' => 'Current Stock', 'class' => 'text-right'],
                ['title' => 'Status'],
            ];

            $query = Product::with(['category', 'supplier'])->whereColumn('current_stock', '<=', 'minimum_stock');
            if ($this->categoryId) $query->where('category_id', $this->categoryId);
            if ($this->supplierId) $query->where('supplier_id', $this->supplierId);
            $products = $query->orderBy('current_stock')->get();

            foreach ($products as $p) {
                $rows[] = [
                    ['value' => $p->sku, 'class' => 'font-mono'],
                    ['value' => $p->name],
                    ['value' => $p->category->name ?? 'Uncategorized'],
                    ['value' => $p->supplier->name ?? 'No Supplier'],
                    ['value' => $p->minimum_stock, 'class' => 'text-right'],
                    ['value' => $p->current_stock, 'class' => 'text-right font-bold text-rose-600'],
                    ['value' => $p->current_stock === 0 ? 'OUT OF STOCK' : 'LOW STOCK', 'badge' => $p->current_stock === 0 ? 'danger' : 'warning'],
                ];
            }

            $totals = [
                ['value' => 'Total Alert Items: ' . count($rows)],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
                ['value' => ''],
            ];

        } elseif ($this->reportType === 'supplier') {
            $headers = [
                ['title' => 'Supplier Name'],
                ['title' => 'Contact Person'],
                ['title' => 'Catalog Product Count', 'class' => 'text-right'],
                ['title' => 'Total Quantities Hand', 'class' => 'text-right'],
                ['title' => 'Warehouse Value (Cost)', 'class' => 'text-right'],
            ];

            $suppliers = Supplier::with('products')->where('status', 'active')->orderBy('name')->get();
            
            $globalProducts = 0;
            $globalQty = 0;
            $globalCost = 0.0;

            foreach ($suppliers as $s) {
                $pCount = $s->products->count();
                $qtySum = $s->products->sum('current_stock');
                $valSum = $s->products->sum(function ($p) {
                    return $p->current_stock * $p->cost_price;
                });

                $globalProducts += $pCount;
                $globalQty += $qtySum;
                $globalCost += $valSum;

                $rows[] = [
                    ['value' => $s->name, 'class' => 'font-semibold'],
                    ['value' => $s->contact_person ?: '-'],
                    ['value' => $pCount, 'class' => 'text-right'],
                    ['value' => $qtySum, 'class' => 'text-right'],
                    ['value' => '$' . number_format($valSum, 2), 'class' => 'text-right font-medium'],
                ];
            }

            $totals = [
                ['value' => 'TOTALS'],
                ['value' => ''],
                ['value' => $globalProducts, 'class' => 'text-right'],
                ['value' => $globalQty, 'class' => 'text-right'],
                ['value' => '$' . number_format($globalCost, 2), 'class' => 'text-right'],
            ];
        }

        return compact('headers', 'rows', 'totals');
    }

    public function exportExcel()
    {
        $reportData = $this->getReportDataProperty();
        $headers = collect($reportData['headers'])->pluck('title')->toArray();
        $rows = $reportData['rows'];
        $totals = collect($reportData['totals'])->pluck('value')->toArray();

        $filename = 'sims_' . $this->reportType . '_report_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows, $totals) {
            $handle = fopen('php://output', 'w');
            
            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Header Row
            fputcsv($handle, $headers);

            // Data Rows
            foreach ($rows as $row) {
                $flatRow = [];
                foreach ($row as $cell) {
                    // strip HTML tags or format
                    $flatRow[] = html_entity_decode(strip_tags($cell['value']));
                }
                fputcsv($handle, $flatRow);
            }

            // Totals Row
            if (count($rows) > 0 && count($totals) > 0) {
                fputcsv($handle, []);
                fputcsv($handle, $totals);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function exportPdf()
    {
        $reportData = $this->getReportDataProperty();
        
        $title = 'Inventory Summary Report';
        if ($this->reportType === 'movements') $title = 'Stock Movements Report';
        if ($this->reportType === 'low_stock') $title = 'Low Stock Alert Report';
        if ($this->reportType === 'supplier') $title = 'Supplier Distribution Report';

        $dateRange = 'All Time';
        if ($this->reportType === 'movements') {
            $dateRange = ($this->startDate ?: 'Beginning') . ' to ' . ($this->endDate ?: 'Now');
        }

        $appliedFilters = [];
        if ($this->categoryId) {
            $cat = Category::find($this->categoryId);
            if ($cat) $appliedFilters[] = 'Category: ' . $cat->name;
        }
        if ($this->supplierId) {
            $sup = Supplier::find($this->supplierId);
            if ($sup) $appliedFilters[] = 'Supplier: ' . $sup->name;
        }
        $filters = count($appliedFilters) > 0 ? implode(', ', $appliedFilters) : 'None';

        $data = [
            'title' => $title,
            'operator' => Auth::user()->name,
            'dateRange' => $dateRange,
            'filters' => $filters,
            'headers' => $reportData['headers'],
            'rows' => $reportData['rows'],
            'totals' => $reportData['totals'],
        ];

        $pdf = Pdf::loadView('exports.pdf_report', $data);
        
        $filename = 'sims_' . $this->reportType . '_report_' . now()->format('Ymd_His') . '.pdf';

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">Inventory Reports</flux:heading>
                <flux:subheading>Generate, filter, and export detailed analytical stock sheets and logs.</flux:subheading>
            </div>
            <div class="flex items-center gap-2">
                <flux:button wire:click="exportExcel" icon="arrow-down-tray">Export CSV</flux:button>
                <flux:button wire:click="exportPdf" icon="printer" variant="primary">Export PDF</flux:button>
            </div>
        </div>

        <!-- Configuration Bar -->
        <div class="grid gap-4 bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 {{ $reportType === 'movements' ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-5' : ($reportType === 'supplier' ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1 sm:grid-cols-3') }}">
            <div>
                <flux:select wire:model.live="reportType" label="Report Class" required>
                    <option value="summary">Inventory Valuation Summary</option>
                    <option value="movements">Stock Movement Log</option>
                    <option value="low_stock">Low Stock & Out of Stock Checklist</option>
                    <option value="supplier">Supplier Distribution Sheet</option>
                </flux:select>
            </div>

            @if($reportType === 'movements')
                <div>
                    <flux:input wire:model.live="startDate" type="date" label="Start Date" />
                </div>
                <div>
                    <flux:input wire:model.live="endDate" type="date" label="End Date" />
                </div>
            @endif

            <div>
                <flux:select wire:model.live="categoryId" label="Filter Category">
                    <option value="">All Categories</option>
                    @foreach(Category::all() as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            @if($reportType !== 'supplier')
                <div>
                    <flux:select wire:model.live="supplierId" label="Filter Supplier">
                        <option value="">All Suppliers</option>
                        @foreach(Supplier::all() as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>

        <!-- Preview Area -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="px-6 py-4 border-b border-zinc-150 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 flex items-center justify-between">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Live Report Sheet Preview</span>
                <span class="text-xs text-zinc-400">Total rows: {{ count($this->reportData['rows']) }}</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs font-semibold text-zinc-400 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950/20">
                            @foreach($this->reportData['headers'] as $header)
                                <th class="px-6 py-3 {{ $header['class'] ?? '' }}">{{ $header['title'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($this->reportData['rows'] as $row)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                @foreach($row as $cell)
                                    <td class="px-6 py-3.5 {{ $cell['class'] ?? '' }}">
                                        @if(isset($cell['badge']))
                                            @if($cell['badge'] === 'success')
                                                <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">{{ $cell['value'] }}</span>
                                            @elseif($cell['badge'] === 'danger')
                                                <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">{{ $cell['value'] }}</span>
                                            @elseif($cell['badge'] === 'warning')
                                                <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">{{ $cell['value'] }}</span>
                                            @else
                                                <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">{{ $cell['value'] }}</span>
                                            @endif
                                        @else
                                            {!! $cell['value'] !!}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($this->reportData['headers']) }}" class="px-6 py-10 text-center text-zinc-500">
                                    No data match the current search filters.
                                </td>
                            </tr>
                        @endforelse

                        @if(count($this->reportData['rows']) > 0 && count($this->reportData['totals']) > 0)
                            <tr class="bg-zinc-100 dark:bg-zinc-950 font-bold border-t-2 border-zinc-300 dark:border-zinc-800">
                                @foreach($this->reportData['totals'] as $total)
                                    <td class="px-6 py-4 {{ $total['class'] ?? '' }}">{!! $total['value'] !!}</td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
