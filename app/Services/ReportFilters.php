<?php

namespace App\Services;

/**
 * Immutable filter set shared by every report in the reporting module.
 */
class ReportFilters
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $startDate = null,
        public readonly ?string $endDate = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $supplierId = null,
        public readonly ?string $severity = null,
        public readonly int $inactivityDays = 30,
        public readonly ?string $transactionType = null,
        public readonly ?int $productId = null,
        public readonly ?string $purchaseOrderStatus = null,
    ) {}

    /**
     * Build a filter set from raw (string based) component state.
     *
     * @param  array{search?: string|null, startDate?: string|null, endDate?: string|null, categoryId?: int|string|null, supplierId?: int|string|null, severity?: string|null, inactivityDays?: int|string|null, transactionType?: string|null, productId?: int|string|null, purchaseOrderStatus?: string|null}  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            search: self::nullableString($state['search'] ?? null),
            startDate: self::nullableString($state['startDate'] ?? null),
            endDate: self::nullableString($state['endDate'] ?? null),
            categoryId: self::nullableInt($state['categoryId'] ?? null),
            supplierId: self::nullableInt($state['supplierId'] ?? null),
            severity: self::nullableString($state['severity'] ?? null),
            inactivityDays: self::nullableInt($state['inactivityDays'] ?? null) ?? 30,
            transactionType: self::nullableString($state['transactionType'] ?? null),
            productId: self::nullableInt($state['productId'] ?? null),
            purchaseOrderStatus: self::nullableString($state['purchaseOrderStatus'] ?? null),
        );
    }

    /**
     * Whether at least one user supplied filter is active.
     */
    public function hasAny(): bool
    {
        return $this->search !== null
            || $this->startDate !== null
            || $this->endDate !== null
            || $this->categoryId !== null
            || $this->supplierId !== null
            || $this->severity !== null
            || $this->transactionType !== null
            || $this->productId !== null
            || $this->purchaseOrderStatus !== null;
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
