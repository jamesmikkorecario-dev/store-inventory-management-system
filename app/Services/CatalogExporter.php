<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams filtered CSV exports for the catalog entities (products, suppliers,
 * categories).
 *
 * Mirrors the conventions of {@see ReportExporter}: shared row generators,
 * UTF-8 BOM output for Excel, a totals row and timestamped `sims_*` filenames.
 */
class CatalogExporter
{
    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * Column titles for the given catalog entity.
     *
     * @return list<string>
     */
    public function headers(string $entity): array
    {
        return match ($entity) {
            'suppliers' => [
                'Supplier Name',
                'Contact Person',
                'Email',
                'Phone',
                'Address',
                'Status',
                'Products Supplied',
                'Created',
            ],
            'categories' => [
                'Category Name',
                'Description',
                'Products Cataloged',
                'Created',
            ],
            default => [
                'SKU',
                'Identifier',
                'Product Name',
                'Description',
                'Category',
                'Supplier',
                'Cost Price',
                'Selling Price',
                'Current Stock',
                'Minimum Stock',
                'Stock Status',
                'Status',
                'Created',
            ],
        };
    }

    /**
     * Stream export rows one at a time so large catalogs stay memory safe.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(string $entity, CatalogFilters $filters): Generator
    {
        switch ($entity) {
            case 'suppliers':
                /** @var Supplier $supplier */
                foreach ($this->catalog->supplierQuery($filters)->lazy(500) as $supplier) {
                    yield [
                        $supplier->name,
                        $supplier->contact_person ?: '-',
                        $supplier->email ?: '-',
                        $supplier->phone ?: '-',
                        $supplier->address ?: '-',
                        ucfirst($supplier->status),
                        (string) (int) $supplier->products_count,
                        $supplier->created_at?->format('Y-m-d H:i') ?? '-',
                    ];
                }

                break;

            case 'categories':
                /** @var Category $category */
                foreach ($this->catalog->categoryQuery($filters)->lazy(500) as $category) {
                    yield [
                        $category->name,
                        $category->description ?: '-',
                        (string) (int) $category->products_count,
                        $category->created_at?->format('Y-m-d H:i') ?? '-',
                    ];
                }

                break;

            default:
                /** @var Product $product */
                foreach ($this->catalog->productQuery($filters)->lazy(500) as $product) {
                    yield [
                        $product->sku,
                        $product->identifier ?: '-',
                        $product->name,
                        $product->description ?: '-',
                        $product->category->name ?? 'Uncategorized',
                        $product->supplier->name ?? 'Unassigned',
                        $this->money((float) $product->cost_price),
                        $this->money((float) $product->selling_price),
                        (string) $product->current_stock,
                        (string) $product->minimum_stock,
                        $this->stockStatusLabel($product),
                        ucfirst($product->status),
                        $product->created_at?->format('Y-m-d H:i') ?? '-',
                    ];
                }
        }
    }

    /**
     * Totals row derived from the full filtered dataset.
     *
     * @return list<string>
     */
    public function totals(string $entity, CatalogFilters $filters): array
    {
        switch ($entity) {
            case 'suppliers':
                $query = $this->catalog->supplierQuery($filters);
                $productCount = Product::query()
                    ->whereIn('supplier_id', $query->clone()->reorder()->select('suppliers.id'))
                    ->count();

                return [
                    'TOTALS ('.$query->clone()->reorder()->count().' suppliers)',
                    '',
                    '',
                    '',
                    '',
                    'Active: '.$query->clone()->reorder()->where('status', 'active')->count(),
                    (string) $productCount,
                    '',
                ];

            case 'categories':
                $query = $this->catalog->categoryQuery($filters);
                $productCount = Product::query()
                    ->whereIn('category_id', $query->clone()->reorder()->select('categories.id'))
                    ->count();

                return [
                    'TOTALS ('.$query->clone()->reorder()->count().' categories)',
                    '',
                    (string) $productCount,
                    '',
                ];

            default:
                /** @var array<string, mixed> $aggregates */
                $aggregates = (array) ($this->catalog->productQuery($filters)
                    ->clone()
                    ->reorder()
                    ->toBase()
                    ->selectRaw('COUNT(*) as product_count')
                    ->selectRaw('COALESCE(SUM(current_stock), 0) as total_units')
                    ->selectRaw('COALESCE(SUM(current_stock * cost_price), 0) as total_cost_value')
                    ->selectRaw('COALESCE(SUM(current_stock * selling_price), 0) as total_retail_value')
                    ->first() ?? []);

                return [
                    'TOTALS ('.(int) ($aggregates['product_count'] ?? 0).' products)',
                    '',
                    '',
                    '',
                    '',
                    '',
                    'Cost value: '.$this->money((float) ($aggregates['total_cost_value'] ?? 0)),
                    'Retail value: '.$this->money((float) ($aggregates['total_retail_value'] ?? 0)),
                    (string) (int) ($aggregates['total_units'] ?? 0),
                    '',
                    '',
                    '',
                    '',
                ];
        }
    }

    /**
     * Stream a UTF-8 (Excel compatible) CSV export of the filtered catalog.
     */
    public function csv(string $entity, CatalogFilters $filters): StreamedResponse
    {
        $entity = array_key_exists($entity, CatalogService::ENTITIES) ? $entity : 'products';
        $filename = $this->filename($entity);
        $headers = $this->headers($entity);
        $rows = $this->rows($entity, $filters);
        $totals = $this->totals($entity, $filters);

        return response()->streamDownload(function () use ($headers, $rows, $totals): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // UTF-8 BOM so Excel opens the file with the correct encoding.
            fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
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
     * Export filename including the entity and a timestamp.
     */
    public function filename(string $entity): string
    {
        return 'sims_'.$entity.'_export_'.now()->format('Ymd_His').'.csv';
    }

    private function stockStatusLabel(Product $product): string
    {
        if ($product->current_stock <= 0) {
            return 'Out of Stock';
        }

        return $product->isLowStock() ? 'Low Stock' : 'In Stock';
    }

    private function money(float $value): string
    {
        return '$'.number_format($value, 2);
    }
}
