<?php

namespace App\Services;

/**
 * Outcome of a CSV product import.
 *
 * Holds the summary counters plus the row level errors used to build the
 * downloadable validation report.
 */
class ProductImportResult
{
    /**
     * @param  list<array{row: int, sku: string, messages: list<string>}>  $rowErrors
     * @param  list<string>  $fatalErrors
     */
    public function __construct(
        public readonly int $totalRows = 0,
        public readonly int $imported = 0,
        public readonly array $rowErrors = [],
        public readonly array $fatalErrors = [],
    ) {}

    /**
     * A file level failure: nothing was imported.
     *
     * @param  list<string>  $messages
     */
    public static function fatal(array $messages): self
    {
        return new self(fatalErrors: $messages);
    }

    /**
     * Number of rows rejected by validation.
     */
    public function skipped(): int
    {
        return count($this->rowErrors);
    }

    /**
     * Whether the file itself could not be processed.
     */
    public function failed(): bool
    {
        return $this->fatalErrors !== [];
    }

    /**
     * Whether any row was rejected.
     */
    public function hasRowErrors(): bool
    {
        return $this->rowErrors !== [];
    }

    /**
     * Serializable representation for Livewire component state.
     *
     * @return array{total_rows: int, imported: int, skipped: int, row_errors: list<array{row: int, sku: string, messages: list<string>}>, fatal_errors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'imported' => $this->imported,
            'skipped' => $this->skipped(),
            'row_errors' => $this->rowErrors,
            'fatal_errors' => $this->fatalErrors,
        ];
    }
}
