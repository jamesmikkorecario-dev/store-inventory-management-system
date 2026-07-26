<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the filtered catalog queries used by the products, suppliers and
 * categories pages.
 *
 * The Livewire pages and the CSV exports both consume these builders so a
 * filtered export always matches the filtered table.
 */
class CatalogService
{
    /**
     * Entities that can be listed and exported.
     */
    public const ENTITIES = [
        'products' => 'Products',
        'suppliers' => 'Suppliers',
        'categories' => 'Categories',
    ];

    /**
     * Filtered product catalog query.
     *
     * @return Builder<Product>
     */
    public function productQuery(CatalogFilters $filters): Builder
    {
        $query = Product::query()->with(['category', 'supplier']);

        if ($filters->restrictToSupplierId !== null) {
            $query->where('supplier_id', $filters->restrictToSupplierId);
        }

        if ($filters->search !== null) {
            $search = $filters->search;

            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('identifier', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if ($filters->categoryId !== null) {
            $query->where('category_id', $filters->categoryId);
        }

        if ($filters->supplierId !== null && $filters->restrictToSupplierId === null) {
            $query->where('supplier_id', $filters->supplierId);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        match ($filters->stockStatus) {
            'low' => $query->whereColumn('current_stock', '<=', 'minimum_stock'),
            'instock' => $query->where('current_stock', '>', 0),
            'out' => $query->where('current_stock', '=', 0),
            default => null,
        };

        // Deterministic ordering keeps chunked exports free of skipped rows.
        return $query->latest()->orderByDesc('id');
    }

    /**
     * Filtered supplier query including the supplied product count.
     *
     * @return Builder<Supplier>
     */
    public function supplierQuery(CatalogFilters $filters): Builder
    {
        $query = Supplier::query()->withCount('products');

        if ($filters->search !== null) {
            $search = $filters->search;

            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('contact_person', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        return $query->latest()->orderByDesc('id');
    }

    /**
     * Filtered category query including the cataloged product count.
     *
     * @return Builder<Category>
     */
    public function categoryQuery(CatalogFilters $filters): Builder
    {
        $query = Category::query()->withCount('products');

        if ($filters->search !== null) {
            $search = $filters->search;

            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->orderByDesc('id');
    }
}
