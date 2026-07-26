<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Imports products from an uploaded CSV file.
 *
 * The file is fully validated before a single record is written and the insert
 * runs inside one transaction, so an import either lands completely or leaves
 * the catalog untouched. Rejected rows never block the valid ones; they are
 * reported back row by row for the downloadable validation report.
 */
class ProductImportService
{
    /**
     * Columns every import file must provide.
     *
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = [
        'sku',
        'name',
        'category',
        'supplier',
        'cost_price',
        'selling_price',
        'minimum_stock',
    ];

    /**
     * Columns that may be omitted.
     *
     * @var list<string>
     */
    public const OPTIONAL_COLUMNS = [
        'description',
        'status',
    ];

    /**
     * Allowed product statuses.
     *
     * @var list<string>
     */
    public const STATUSES = ['active', 'inactive', 'discontinued'];

    /**
     * Upper bound on data rows per file, keeping imports responsive.
     */
    public const MAX_ROWS = 2000;

    /**
     * Validate and import the CSV file found at the given path.
     */
    public function import(string $path): ProductImportResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ProductImportResult::fatal(['The uploaded file could not be read. Please upload the file again.']);
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ProductImportResult::fatal(['The uploaded file could not be opened. Please upload the file again.']);
        }

        try {
            $header = fgetcsv($handle);

            if (! is_array($header)) {
                return ProductImportResult::fatal(['The CSV file is empty. Download the template to see the expected format.']);
            }

            $columns = $this->normalizeHeader($header);
            $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columns));

            if ($missing !== []) {
                return ProductImportResult::fatal([
                    'The CSV file is missing the required column(s): '.implode(', ', $missing).'.',
                    'Expected columns: '.implode(', ', self::REQUIRED_COLUMNS).' (optional: '.implode(', ', self::OPTIONAL_COLUMNS).').',
                ]);
            }

            $rows = [];
            $line = 1;

            while (($record = fgetcsv($handle)) !== false) {
                $line++;

                if ($this->isBlankRecord($record)) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    return ProductImportResult::fatal([
                        'The CSV file contains more than '.number_format(self::MAX_ROWS).' data rows.',
                        'Split the file into smaller batches and import them one at a time.',
                    ]);
                }

                $rows[] = ['line' => $line, 'record' => $record];
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            return ProductImportResult::fatal(['The CSV file does not contain any data rows.']);
        }

        return $this->processRows($columns, $rows);
    }

    /**
     * Stream the import template so operators start from a valid file.
     */
    public function template(): StreamedResponse
    {
        $filename = 'sims_product_import_template.csv';
        $columns = [...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS];
        $example = [
            'SKU-EXAMPLE-001',
            'Example Mechanical Keyboard',
            'Computer Accessories',
            'Apex Electronics Corp',
            '45.00',
            '79.99',
            '10',
            'Optional product description',
            'active',
        ];

        return response()->streamDownload(function () use ($columns, $example): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, $columns);
            fputcsv($handle, $example);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Stream the row level validation report for a completed import.
     *
     * @param  list<array{row: int, sku: string, messages: list<string>}>  $rowErrors
     */
    public function errorReport(array $rowErrors): StreamedResponse
    {
        $filename = 'sims_product_import_errors_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rowErrors): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['CSV Row', 'SKU', 'Validation Errors']);

            foreach ($rowErrors as $error) {
                fputcsv($handle, [
                    (string) $error['row'],
                    $error['sku'] === '' ? '-' : $error['sku'],
                    implode(' | ', $error['messages']),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Validate every row, then persist the valid ones in a single transaction.
     *
     * @param  list<string>  $columns
     * @param  list<array{line: int, record: array<int, string|null>}>  $rows
     */
    private function processRows(array $columns, array $rows): ProductImportResult
    {
        $categories = $this->lookupMap(Category::query()->get(['id', 'name'])->all());
        $suppliers = $this->lookupMap(Supplier::query()->where('status', 'active')->get(['id', 'name'])->all());
        $inactiveSuppliers = $this->lookupMap(Supplier::query()->where('status', '!=', 'active')->get(['id', 'name'])->all());

        $existingSkus = $this->existingSkus($columns, $rows);

        $payloads = [];
        $rowErrors = [];
        $seenSkus = [];

        foreach ($rows as $row) {
            $data = $this->mapRecord($columns, $row['record']);
            $messages = $this->validateRow($data);

            $sku = $data['sku'];
            $skuKey = mb_strtolower($sku);

            if ($sku !== '') {
                if (in_array($skuKey, $existingSkus, true)) {
                    $messages[] = 'SKU "'.$sku.'" already exists in the catalog.';
                } elseif (isset($seenSkus[$skuKey])) {
                    $messages[] = 'SKU "'.$sku.'" is duplicated in this file (first seen on row '.$seenSkus[$skuKey].').';
                }
            }

            $categoryId = $categories[mb_strtolower($data['category'])] ?? null;

            if ($data['category'] === '') {
                $messages[] = 'Category is required.';
            } elseif ($categoryId === null) {
                $messages[] = 'Category "'.$data['category'].'" does not exist. Create it first or correct the spelling.';
            }

            $supplierId = $suppliers[mb_strtolower($data['supplier'])] ?? null;

            if ($data['supplier'] === '') {
                $messages[] = 'Supplier is required.';
            } elseif ($supplierId === null) {
                $messages[] = isset($inactiveSuppliers[mb_strtolower($data['supplier'])])
                    ? 'Supplier "'.$data['supplier'].'" is not active. Activate the supplier first.'
                    : 'Supplier "'.$data['supplier'].'" does not exist. Create it first or correct the spelling.';
            }

            if (count($row['record']) !== count($columns)) {
                $messages[] = 'Row has '.count($row['record']).' column(s) but the header defines '.count($columns).'.';
            }

            if ($messages !== []) {
                $rowErrors[] = [
                    'row' => $row['line'],
                    'sku' => $sku,
                    'messages' => array_values(array_unique($messages)),
                ];

                continue;
            }

            $seenSkus[$skuKey] = $row['line'];

            $payloads[] = [
                'sku' => $sku,
                'name' => $data['name'],
                'description' => $data['description'] === '' ? null : $data['description'],
                'category_id' => $categoryId,
                'supplier_id' => $supplierId,
                'cost_price' => round((float) $data['cost_price'], 2),
                'selling_price' => round((float) $data['selling_price'], 2),
                'minimum_stock' => (int) $data['minimum_stock'],
                // Stock is only ever moved through inventory transactions.
                'current_stock' => 0,
                'status' => $data['status'] === '' ? 'active' : mb_strtolower($data['status']),
            ];
        }

        if ($payloads === []) {
            return new ProductImportResult(
                totalRows: count($rows),
                imported: 0,
                rowErrors: $rowErrors,
            );
        }

        try {
            DB::transaction(function () use ($payloads): void {
                foreach ($payloads as $payload) {
                    Product::create($payload);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            return ProductImportResult::fatal([
                'The import was rolled back because of an unexpected database error. No products were created.',
                'Please verify the file contents and try again.',
            ]);
        }

        return new ProductImportResult(
            totalRows: count($rows),
            imported: count($payloads),
            rowErrors: $rowErrors,
        );
    }

    /**
     * Validate the scalar fields of a single row.
     *
     * @param  array<string, string>  $data
     * @return list<string>
     */
    private function validateRow(array $data): array
    {
        // Optional columns arrive as empty strings; treat them as "not supplied".
        /** @var array<string, string|null> $attributes */
        $attributes = $data;
        $attributes['description'] = $data['description'] === '' ? null : $data['description'];
        $attributes['status'] = $data['status'] === '' ? null : mb_strtolower($data['status']);

        $validator = Validator::make($attributes, [
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'minimum_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
        ], [
            'sku.required' => 'SKU is required.',
            'sku.max' => 'SKU may not be longer than 100 characters.',
            'name.required' => 'Product name is required.',
            'name.max' => 'Product name may not be longer than 255 characters.',
            'cost_price.required' => 'Cost price is required.',
            'cost_price.numeric' => 'Cost price must be a number (for example 45.00).',
            'cost_price.min' => 'Cost price cannot be negative.',
            'cost_price.max' => 'Cost price may not exceed 99,999,999.99.',
            'selling_price.required' => 'Selling price is required.',
            'selling_price.numeric' => 'Selling price must be a number (for example 79.99).',
            'selling_price.min' => 'Selling price cannot be negative.',
            'selling_price.max' => 'Selling price may not exceed 99,999,999.99.',
            'minimum_stock.required' => 'Minimum stock is required.',
            'minimum_stock.integer' => 'Minimum stock must be a whole number.',
            'minimum_stock.min' => 'Minimum stock cannot be negative.',
            'minimum_stock.max' => 'Minimum stock may not exceed 1,000,000.',
            'status.in' => 'Status must be one of: '.implode(', ', self::STATUSES).'.',
        ]);

        /** @var list<string> $messages */
        $messages = $validator->errors()->all();

        return $messages;
    }

    /**
     * Existing catalog SKUs (including soft deleted) referenced by the file.
     *
     * @param  list<string>  $columns
     * @param  list<array{line: int, record: array<int, string|null>}>  $rows
     * @return list<string>
     */
    private function existingSkus(array $columns, array $rows): array
    {
        $skus = [];

        foreach ($rows as $row) {
            $sku = $this->mapRecord($columns, $row['record'])['sku'];

            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        if ($skus === []) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = Product::withTrashed()
            ->whereIn('sku', array_values(array_unique($skus)))
            ->pluck('sku')
            ->all();

        return array_map(fn (string $sku): string => mb_strtolower($sku), $existing);
    }

    /**
     * Normalize header labels to snake_case keys.
     *
     * @param  array<int, string|null>  $header
     * @return list<string>
     */
    private function normalizeHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $label) {
            $label = (string) $label;

            if ($index === 0) {
                // Strip a UTF-8 BOM written by Excel.
                $label = preg_replace('/^\x{FEFF}/u', '', $label) ?? $label;
            }

            $normalized = mb_strtolower(trim($label));
            $normalized = (string) preg_replace('/[\s\-]+/', '_', $normalized);

            $columns[] = $normalized;
        }

        return $columns;
    }

    /**
     * Map a CSV record onto the known column keys, trimming every value.
     *
     * @param  list<string>  $columns
     * @param  array<int, string|null>  $record
     * @return array<string, string>
     */
    private function mapRecord(array $columns, array $record): array
    {
        $data = array_fill_keys([...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS], '');
        $values = array_values($record);

        foreach ($columns as $index => $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            $data[$column] = trim((string) ($values[$index] ?? ''));
        }

        return $data;
    }

    /**
     * Whether a CSV record is an empty line.
     *
     * @param  array<int, string|null>  $record
     */
    private function isBlankRecord(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a lowercase name to id lookup for the given models.
     *
     * @param  array<int, Category|Supplier>  $models
     * @return array<string, int>
     */
    private function lookupMap(array $models): array
    {
        $map = [];

        foreach ($models as $model) {
            $map[mb_strtolower($model->name)] = $model->id;
        }

        return $map;
    }
}
