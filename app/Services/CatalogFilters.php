<?php

namespace App\Services;

/**
 * Immutable filter set shared by the catalog listings (products, suppliers,
 * categories) and their CSV exports.
 *
 * Keeping the filter state in one object guarantees an export always mirrors
 * exactly what the operator sees on screen.
 */
class CatalogFilters
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $supplierId = null,
        public readonly ?string $stockStatus = null,
        public readonly ?string $status = null,
        public readonly ?int $restrictToSupplierId = null,
    ) {}

    /**
     * Build a filter set from raw (string based) Livewire component state.
     *
     * @param  array{search?: string|null, categoryId?: int|string|null, supplierId?: int|string|null, stockStatus?: string|null, status?: string|null, restrictToSupplierId?: int|string|null}  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            search: self::nullableString($state['search'] ?? null),
            categoryId: self::nullableInt($state['categoryId'] ?? null),
            supplierId: self::nullableInt($state['supplierId'] ?? null),
            stockStatus: self::nullableString($state['stockStatus'] ?? null),
            status: self::nullableString($state['status'] ?? null),
            restrictToSupplierId: self::nullableInt($state['restrictToSupplierId'] ?? null),
        );
    }

    /**
     * Whether at least one user supplied filter is active.
     */
    public function hasAny(): bool
    {
        return $this->search !== null
            || $this->categoryId !== null
            || $this->supplierId !== null
            || $this->stockStatus !== null
            || $this->status !== null;
    }

    private static function nullableString(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }

    private static function nullableInt(int|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
