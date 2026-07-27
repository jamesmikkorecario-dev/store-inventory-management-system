<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Services\CatalogExporter;
use App\Services\CatalogFilters;
use App\Services\CatalogService;
use App\Services\ProductBulkActionService;
use App\Services\ProductImportService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Flux\Flux;

new #[Title('Product Management')] class extends Component {
    use WithPagination, WithFileUploads;

    public const PER_PAGE = 10;

    public string $search = '';
    public string $filterCategory = '';
    public string $filterSupplier = '';
    public string $filterStockStatus = '';
    public string $filterStatus = '';

    // Form fields
    public ?int $productId = null;
    public string $sku = '';
    public string $identifier = '';
    public string $name = '';
    public string $description = '';
    public ?int $categoryId = null;
    public ?int $supplierId = null;
    public float $costPrice = 0.00;
    public float $sellingPrice = 0.00;
    public int $minimumStock = 10;
    public string $status = 'active';
    public string $previewFormat = 'barcode';

    public $image = null;
    public ?string $existingImageUrl = null;
    public bool $removeImage = false;
    public bool $showImagePreviewModal = false;
    public ?string $previewImageUrl = null;

    public bool $showFormModal = false;
    public bool $showDeleteModal = false;

    /**
     * Selected product ids for bulk actions (Livewire binds checkbox values as strings).
     *
     * @var list<string>
     */
    public array $selected = [];

    public bool $selectPage = false;
    public string $bulkAction = '';
    public ?int $bulkCategoryId = null;
    public ?int $bulkSupplierId = null;
    public ?int $bulkMinimumStock = null;
    public string $bulkStatus = 'active';
    public bool $showBulkModal = false;
    public bool $showBulkArchiveModal = false;

    // CSV import
    public bool $showImportModal = false;
    public $importFile = null;

    /**
     * Summary of the last import: counters plus row level errors.
     *
     * @var array<string, mixed>
     */
    public array $importSummary = [];

    // Permissions (locked: never trust the browser with authorization state)
    #[Locked]
    public bool $isReadOnly = true;

    #[Locked]
    public bool $isSupplier = false;

    #[Locked]
    public ?int $userSupplierId = null;

    #[Locked]
    public bool $canImport = false;

    #[Locked]
    public bool $canBulkManage = false;

    #[Locked]
    public bool $canExportCatalog = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->isSupplier = $user->hasRole('Supplier');
        $this->userSupplierId = $user->supplier_id;

        if ($this->isSupplier) {
            $this->isReadOnly = true;
        } else {
            $this->isReadOnly = !$user->can('manage products');
        }

        // Suppliers never get bulk or import/export tooling.
        $this->canImport = !$this->isSupplier && $user->can('import products');
        $this->canBulkManage = !$this->isSupplier && $user->can('bulk manage products');
        $this->canExportCatalog = !$this->isSupplier && $user->can('export catalog');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    #[On('products-updated')]
    #[On('categories-updated')]
    #[On('suppliers-updated')]
    #[On('inventory-updated')]
    public function refreshData(): void
    {
        // Component will re-render
    }

    public function updatedFilterCategory(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFilterSupplier(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFilterStockStatus(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * Paging invalidates the "select all on this page" toggle.
     */
    public function updatedPaginators(mixed $page = null, ?string $pageName = null): void
    {
        $this->selectPage = false;
    }

    public function openCreateModal(): void
    {
        if ($this->isReadOnly) abort(403);
        $this->resetForm();
        $this->identifier = Product::generateUniqueIdentifier();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        if ($this->isReadOnly) abort(403);
        $this->resetForm();
        $product = Product::findOrFail($id);
        $this->productId = $product->id;
        $this->sku = $product->sku;
        $this->identifier = $product->identifier ?? '';
        $this->name = $product->name;
        $this->description = $product->description ?? '';
        $this->categoryId = $product->category_id;
        $this->supplierId = $product->supplier_id;
        $this->costPrice = $product->cost_price;
        $this->sellingPrice = $product->selling_price;
        $this->minimumStock = $product->minimum_stock;
        $this->status = $product->status;

        $this->existingImageUrl = $product->image_path ? $product->imageUrl() : null;
        $this->removeImage = false;
        $this->image = null;

        $this->showFormModal = true;
    }

    public function saveProduct(): void
    {
        if ($this->isReadOnly) abort(403);

        $rules = [
            'sku' => 'required|string|max:100|unique:products,sku,' . ($this->productId ?: 'NULL'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'categoryId' => 'required|exists:categories,id',
            'supplierId' => 'required|exists:suppliers,id',
            'costPrice' => 'required|numeric|min:0',
            'sellingPrice' => 'required|numeric|min:0',
            'minimumStock' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive,discontinued',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];

        $messages = [
            'sku.required' => 'SKU is required.',
            'sku.unique' => 'SKU has already been taken.',
            'name.required' => 'Product name is required.',
            'categoryId.required' => 'Category is required.',
            'categoryId.exists' => 'Selected category is invalid.',
            'supplierId.required' => 'Supplier is required.',
            'supplierId.exists' => 'Selected supplier is invalid.',
            'costPrice.required' => 'Cost price is required.',
            'sellingPrice.required' => 'Selling price is required.',
            'minimumStock.required' => 'Minimum stock is required.',
            'status.required' => 'Status is required.',
        ];

        $validated = $this->validate($rules, $messages);

        $imagePath = null;
        if ($this->image) {
            $imagePath = $this->image->store('products', 'public');
        }

        if ($this->productId) {
            $product = Product::findOrFail($this->productId);
            
            // Handle image changes
            if ($imagePath) {
                if ($product->image_path) {
                    Storage::disk('public')->delete($product->image_path);
                }
                $product->update(['image_path' => $imagePath]);
            } elseif ($this->removeImage && $product->image_path) {
                Storage::disk('public')->delete($product->image_path);
                $product->update(['image_path' => null]);
            }

            $product->update([
                'sku' => $this->sku,
                'name' => $this->name,
                'description' => $this->description,
                'category_id' => $this->categoryId,
                'supplier_id' => $this->supplierId,
                'cost_price' => $this->costPrice,
                'selling_price' => $this->sellingPrice,
                'minimum_stock' => $this->minimumStock,
                'status' => $this->status,
            ]);
            $this->dispatch('products-updated');
            $this->dispatch('dashboard-updated');
            Flux::toast(variant: 'success', text: 'Updated successfully.');
        } else {
            Product::create([
                'sku' => $this->sku,
                'name' => $this->name,
                'description' => $this->description,
                'category_id' => $this->categoryId,
                'supplier_id' => $this->supplierId,
                'cost_price' => $this->costPrice,
                'selling_price' => $this->sellingPrice,
                'minimum_stock' => $this->minimumStock,
                'current_stock' => 0, // Enforce starting stock as 0. Stock changes are managed by transactions.
                'status' => $this->status,
                'image_path' => $imagePath,
            ]);
            $this->dispatch('products-updated');
            $this->dispatch('dashboard-updated');
            Flux::toast(variant: 'success', text: 'Created successfully.');
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        if ($this->isReadOnly) abort(403);
        $this->productId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteProduct(): void
    {
        if ($this->isReadOnly) abort(403);

        $product = Product::findOrFail($this->productId);

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        // Prevent soft deleting if product has transaction history
        if ($product->inventoryTransactions()->count() > 0) {
            Flux::toast(
                variant: 'danger', 
                text: __('Cannot delete product. It has ' . $product->inventoryTransactions()->count() . ' recorded transactions in audit history. Mark as discontinued instead.')
            );
            $this->showDeleteModal = false;
            return;
        }

        $product->delete();
        $this->dispatch('products-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(variant: 'success', text: 'Deleted successfully.');
        $this->showDeleteModal = false;
        $this->resetForm();
    }

    public function removeProductImage(): void
    {
        $this->removeImage = true;
        $this->existingImageUrl = null;
        $this->image = null;
    }

    public function openImagePreview(string $url): void
    {
        $this->previewImageUrl = $url;
        $this->showImagePreviewModal = true;
    }

    // ─── Bulk selection ───────────────────────────────────────────────────────

    /**
     * Toggle every product on the current page.
     */
    public function updatedSelectPage(bool $value): void
    {
        $pageIds = $this->currentPageIds();

        $selected = $value
            ? array_values(array_unique([...$this->selected, ...$pageIds]))
            : array_values(array_diff($this->selected, $pageIds));

        if (count($selected) > ProductBulkActionService::MAX_SELECTION) {
            Flux::toast(
                variant: 'warning',
                text: 'A bulk action can cover at most '.number_format(ProductBulkActionService::MAX_SELECTION).' products. Narrow your filters and work in batches.'
            );

            $selected = array_slice($selected, 0, ProductBulkActionService::MAX_SELECTION);
        }

        $this->selected = $selected;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    // ─── Bulk actions ─────────────────────────────────────────────────────────

    public function openBulkModal(string $action): void
    {
        $this->authorizeBulk();

        if (!$this->hasSelection()) {
            return;
        }

        abort_unless(in_array($action, ['category', 'supplier', 'minimum_stock', 'status'], true), 404);

        $this->resetValidation();
        $this->bulkAction = $action;
        $this->bulkCategoryId = null;
        $this->bulkSupplierId = null;
        $this->bulkMinimumStock = null;
        $this->bulkStatus = 'active';
        $this->showBulkModal = true;
    }

    public function applyBulkAction(): void
    {
        $this->authorizeBulk();

        if (!$this->hasSelection()) {
            $this->showBulkModal = false;
            return;
        }

        $service = app(ProductBulkActionService::class);

        switch ($this->bulkAction) {
            case 'category':
                $this->validate(
                    ['bulkCategoryId' => ['required', 'integer', 'exists:categories,id']],
                    [
                        'bulkCategoryId.required' => 'Select a category to assign.',
                        'bulkCategoryId.exists' => 'Selected category is invalid.',
                    ]
                );
                $updated = $service->updateCategory($this->selectedIds(), (int) $this->bulkCategoryId);
                $summary = 'reassigned to the selected category';
                break;

            case 'supplier':
                $this->validate(
                    ['bulkSupplierId' => ['required', 'integer', 'exists:suppliers,id']],
                    [
                        'bulkSupplierId.required' => 'Select a supplier to assign.',
                        'bulkSupplierId.exists' => 'Selected supplier is invalid.',
                    ]
                );
                $updated = $service->updateSupplier($this->selectedIds(), (int) $this->bulkSupplierId);
                $summary = 'reassigned to the selected supplier';
                break;

            case 'minimum_stock':
                $this->validate(
                    ['bulkMinimumStock' => ['required', 'integer', 'min:0', 'max:1000000']],
                    [
                        'bulkMinimumStock.required' => 'Enter the new minimum stock level.',
                        'bulkMinimumStock.integer' => 'Minimum stock must be a whole number.',
                        'bulkMinimumStock.min' => 'Minimum stock cannot be negative.',
                    ]
                );
                $updated = $service->updateMinimumStock($this->selectedIds(), (int) $this->bulkMinimumStock);
                $summary = 'updated with the new minimum stock level';
                break;

            case 'status':
                $this->validate(
                    ['bulkStatus' => ['required', 'in:active,inactive,discontinued']],
                    ['bulkStatus.in' => 'Selected status is invalid.']
                );
                $updated = $service->updateStatus($this->selectedIds(), $this->bulkStatus);
                $summary = 'marked as '.$this->bulkStatus;
                break;

            default:
                abort(404);
        }

        $this->dispatch('products-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(
            variant: 'success',
            text: $updated.' '.($updated === 1 ? 'product' : 'products').' '.$summary.'.'
        );

        $this->showBulkModal = false;
        $this->bulkAction = '';
        $this->clearSelection();
    }

    public function confirmBulkArchive(): void
    {
        $this->authorizeBulk();

        if (!$this->hasSelection()) {
            return;
        }

        $this->showBulkArchiveModal = true;
    }

    public function bulkArchive(): void
    {
        $this->authorizeBulk();

        if (!$this->hasSelection()) {
            $this->showBulkArchiveModal = false;
            return;
        }

        $result = app(ProductBulkActionService::class)->archive($this->selectedIds());

        $this->dispatch('products-updated');
        $this->dispatch('dashboard-updated');

        if ($result['blocked'] !== []) {
            Flux::toast(
                variant: 'warning',
                text: $result['archived'].' archived. '.count($result['blocked']).' kept because of transaction history: '.implode(', ', array_slice($result['blocked'], 0, 5)).(count($result['blocked']) > 5 ? '…' : '').'. Mark them as discontinued instead.'
            );
        } else {
            Flux::toast(
                variant: 'success',
                text: $result['archived'].' '.($result['archived'] === 1 ? 'product' : 'products').' archived.'
            );
        }

        $this->showBulkArchiveModal = false;
        $this->clearSelection();
    }

    // ─── CSV import ───────────────────────────────────────────────────────────

    public function openImportModal(): void
    {
        $this->authorizeImport();
        $this->reset(['importFile', 'importSummary']);
        $this->resetValidation();
        $this->showImportModal = true;
    }

    public function downloadImportTemplate(): StreamedResponse
    {
        $this->authorizeImport();

        return app(ProductImportService::class)->template();
    }

    public function importProducts(): void
    {
        $this->authorizeImport();

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'importFile.required' => 'Choose a CSV file to import.',
            'importFile.file' => 'The import file could not be read. Please try again.',
            'importFile.mimes' => 'The import file must be a CSV file.',
            'importFile.max' => 'The import file may not be larger than 5 MB.',
        ]);

        $result = app(ProductImportService::class)->import((string) $this->importFile->getRealPath());

        $this->importSummary = $result->toArray();
        $this->importFile = null;

        if ($result->failed()) {
            Flux::toast(variant: 'danger', text: 'Import failed. No products were created.');

            return;
        }

        $this->dispatch('products-updated');
        $this->dispatch('dashboard-updated');

        if ($result->hasRowErrors()) {
            Flux::toast(
                variant: 'warning',
                text: $result->imported.' imported, '.$result->skipped().' row(s) skipped. Download the validation report for details.'
            );

            return;
        }

        Flux::toast(
            variant: 'success',
            text: $result->imported.' '.($result->imported === 1 ? 'product' : 'products').' imported successfully.'
        );
    }

    public function downloadImportErrors(): StreamedResponse
    {
        $this->authorizeImport();

        /** @var list<array{row: int, sku: string, messages: list<string>}> $rowErrors */
        $rowErrors = $this->importSummary['row_errors'] ?? [];

        abort_if($rowErrors === [], 404);

        return app(ProductImportService::class)->errorReport($rowErrors);
    }

    // ─── Export ───────────────────────────────────────────────────────────────

    public function exportCsv(): StreamedResponse
    {
        $user = Auth::user();

        abort_unless(
            $this->canExportCatalog && $user !== null && !$user->hasRole('Supplier') && $user->can('export catalog'),
            403
        );

        return app(CatalogExporter::class)->csv('products', $this->filters());
    }

    private function resetForm(): void
    {
        $this->productId = null;
        $this->sku = '';
        $this->identifier = '';
        $this->name = '';
        $this->description = '';
        $this->categoryId = null;
        $this->supplierId = null;
        $this->costPrice = 0.00;
        $this->sellingPrice = 0.00;
        $this->minimumStock = 10;
        $this->status = 'active';
        $this->previewFormat = 'barcode';
        $this->image = null;
        $this->existingImageUrl = null;
        $this->removeImage = false;
    }

    /**
     * Current catalog filter state, shared by the table and the CSV export.
     */
    protected function filters(): CatalogFilters
    {
        return CatalogFilters::fromArray([
            'search' => $this->search,
            'categoryId' => $this->filterCategory,
            'supplierId' => $this->filterSupplier,
            'stockStatus' => $this->filterStockStatus,
            'status' => $this->filterStatus,
            // Fall back to 0 so a supplier account without a linked supplier sees nothing.
            'restrictToSupplierId' => $this->isSupplier ? ($this->userSupplierId ?? 0) : null,
        ]);
    }

    /**
     * @return list<int>
     */
    protected function selectedIds(): array
    {
        return array_values(array_map('intval', $this->selected));
    }

    /**
     * @return list<string>
     */
    private function currentPageIds(): array
    {
        $ids = app(CatalogService::class)
            ->productQuery($this->filters())
            ->paginate(self::PER_PAGE)
            ->pluck('id')
            ->all();

        return array_map('strval', $ids);
    }

    private function hasSelection(): bool
    {
        if ($this->selected === []) {
            Flux::toast(variant: 'warning', text: 'Select at least one product first.');

            return false;
        }

        return true;
    }

    private function authorizeBulk(): void
    {
        $user = Auth::user();

        abort_unless(
            $this->canBulkManage && $user !== null && !$user->hasRole('Supplier') && $user->can('bulk manage products'),
            403
        );
    }

    private function authorizeImport(): void
    {
        $user = Auth::user();

        abort_unless(
            $this->canImport && $user !== null && !$user->hasRole('Supplier') && $user->can('import products'),
            403
        );
    }

    public function with(): array
    {
        $products = app(CatalogService::class)
            ->productQuery($this->filters())
            ->paginate(self::PER_PAGE);

        return [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'selectedCount' => count($this->selected),
            'selectedSkus' => $this->selected === []
                ? []
                : Product::whereIn('id', $this->selectedIds())->orderBy('sku')->pluck('sku')->all(),
            'importColumns' => ProductImportService::REQUIRED_COLUMNS,
            'importOptionalColumns' => ProductImportService::OPTIONAL_COLUMNS,
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">Product Catalog</flux:heading>
                <flux:subheading>
                    {{ $isSupplier ? 'View your assigned product catalog and stock levels.' : 'Manage corporate product directory, pricing models, and safety stocks.' }}
                </flux:subheading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canExportCatalog)
                    <flux:button wire:click="exportCsv" icon="arrow-down-tray" size="sm" class="w-full sm:w-auto" data-test="export-products">Export CSV</flux:button>
                @endif
                @if($canImport)
                    <flux:button wire:click="openImportModal" icon="arrow-up-tray" size="sm" class="w-full sm:w-auto" data-test="import-products">Import CSV</flux:button>
                @endif
                @if(!$isReadOnly)
                    <flux:button wire:click="openCreateModal" variant="primary" icon="plus" size="sm" class="w-full sm:w-auto">Add Product</flux:button>
                @endif
            </div>
        </div>

        <!-- Bulk Action Toolbar -->
        @if($canBulkManage && $selectedCount > 0)
            <div class="flex flex-col gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/50 dark:bg-blue-950/30 lg:flex-row lg:items-center lg:justify-between" data-test="bulk-toolbar">
                <div class="flex items-center gap-2">
                    <flux:icon name="check-circle" class="size-5 text-blue-600 dark:text-blue-400" />
                    <flux:text class="font-semibold text-blue-900 dark:text-blue-200">
                        {{ $selectedCount }} {{ $selectedCount === 1 ? 'product' : 'products' }} selected
                    </flux:text>
                    <flux:button wire:click="clearSelection" size="xs" variant="ghost" class="text-blue-700 dark:text-blue-300">Clear</flux:button>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:flex lg:flex-wrap lg:items-center">
                    <flux:button wire:click="openBulkModal('category')" size="sm" icon="tag" data-test="bulk-category">Category</flux:button>
                    <flux:button wire:click="openBulkModal('supplier')" size="sm" icon="truck" data-test="bulk-supplier">Supplier</flux:button>
                    <flux:button wire:click="openBulkModal('minimum_stock')" size="sm" icon="bell-alert" data-test="bulk-minimum-stock">Min. Stock</flux:button>
                    <flux:button wire:click="openBulkModal('status')" size="sm" icon="adjustments-horizontal" data-test="bulk-status">Status</flux:button>
                    <flux:button wire:click="confirmBulkArchive" size="sm" icon="archive-box-x-mark" variant="danger" data-test="bulk-archive">Archive</flux:button>
                </div>
            </div>
        @endif

        <!-- Filters Bar -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 items-end bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="sm:col-span-2 relative">
                <flux:input wire:model.live.debounce.300ms="search" label="Search Product" placeholder="Search SKU, identifier, name..." icon="magnifying-glass" />
                <div wire:loading wire:target="search" class="absolute right-3 top-9">
                    <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
                </div>
            </div>
            <div>
                <flux:select wire:model.live="filterCategory" label="Category">
                    <option value="">All Categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            @if(!$isSupplier)
                <div>
                    <flux:select wire:model.live="filterSupplier" label="Supplier">
                        <option value="">All Suppliers</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
            <div>
                <flux:select wire:model.live="filterStockStatus" label="Stock Level">
                    <option value="">All Stock Levels</option>
                    <option value="instock">In Stock</option>
                    <option value="low">Low Stock Alert</option>
                    <option value="out">Out of Stock</option>
                </flux:select>
            </div>
            @if($isSupplier)
                <div>
                    <flux:select wire:model.live="filterStatus" label="Status">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="discontinued">Discontinued</option>
                    </flux:select>
                </div>
            @endif
        </div>

        <!-- Products Table -->
        <div wire:loading wire:target="search, filterCategory, filterSupplier, filterStockStatus, filterStatus, sortBy, gotoPage, nextPage, previousPage" class="flex justify-center py-4 w-full">
            <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
        </div>
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:loading.class="opacity-50 pointer-events-none" wire:target="search, filterCategory, filterSupplier, filterStockStatus, filterStatus, sortBy, gotoPage, nextPage, previousPage">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            @if($canBulkManage)
                                <th scope="col" class="px-4 py-4 w-[48px]">
                                    <flux:checkbox wire:model.live="selectPage" aria-label="Select all products on this page" data-test="select-page" />
                                </th>
                            @endif
                            <th scope="col" class="px-4 py-4 w-[72px]"></th>
                            <th scope="col" class="pl-0 pr-6 py-4 w-[25%]">SKU / Product Name</th>
                            <th scope="col" class="px-6 py-4 w-[15%]">Category</th>
                            @if(!$isSupplier)
                                <th scope="col" class="px-6 py-4 w-[15%]">Supplier</th>
                            @endif
                            <th scope="col" class="px-6 py-4 w-[15%]">Prices (Cost / Selling)</th>
                            <th scope="col" class="px-6 py-4 w-[15%]">Stock Level</th>
                            <th scope="col" class="px-6 py-4 w-[10%] text-center">Status</th>
                            @if(!$isReadOnly)
                                <th scope="col" class="px-6 py-4 text-center w-[5%]">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($products as $product)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30 @if($canBulkManage && in_array((string) $product->id, $selected, true)) bg-blue-50/60 dark:bg-blue-950/20 @endif">
                                @if($canBulkManage)
                                    <td class="px-4 py-4">
                                        <flux:checkbox wire:model.live="selected" value="{{ $product->id }}" aria-label="Select {{ $product->name }}" />
                                    </td>
                                @endif
                                <td class="px-4 py-4">
                                    <button type="button" wire:click="openImagePreview('{{ $product->imageUrl() }}')" class="block size-10 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:ring-2 hover:ring-blue-500 transition-all cursor-pointer">
                                        <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" class="size-full object-contain bg-zinc-100 dark:bg-zinc-800/50" loading="lazy" onerror="this.src='https://placehold.co/400x400/f4f4f5/a1a1aa?text={{ urlencode(Str::limit($product->name, 10)) }}'" />
                                    </button>
                                </td>
                                <td class="pl-0 pr-6 py-4">
                                    <span class="rounded bg-zinc-100 px-2 py-0.5 text-[10px] font-mono font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">SKU: {{ $product->sku }}</span>
                                    @if($product->identifier)
                                        <span class="rounded bg-zinc-100 px-2 py-0.5 text-[10px] font-mono font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 ml-1" title="Product Identifier">{{ $product->identifier }}</span>
                                    @endif
                                    <flux:text class="block font-semibold text-zinc-900 dark:text-white mt-1">{{ $product->name }}</flux:text>
                                    <flux:text class="block text-xs text-zinc-400 max-w-[250px] truncate" title="{{ $product->description }}">{{ $product->description ?: 'No description' }}</flux:text>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400 whitespace-nowrap">
                                        {{ $product->category->name ?? 'Uncategorized' }}
                                    </span>
                                </td>
                                @if(!$isSupplier)
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 max-w-[150px] truncate" title="{{ $product->supplier->name ?? 'No Supplier' }}">
                                        {{ $product->supplier->name ?? 'No Supplier' }}
                                    </td>
                                @endif
                                <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 font-medium">
                                    <div class="flex flex-col space-y-0.5 whitespace-nowrap">
                                        @if(!$isSupplier)
                                            <div class="flex items-center gap-1.5 text-xs">
                                                <span class="text-[10px] uppercase font-bold text-zinc-400 tracking-wider w-8">Cost:</span>
                                                <span>${{ number_format($product->cost_price, 2) }}</span>
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-1.5 text-xs">
                                            <span class="text-[10px] uppercase font-bold text-zinc-400 tracking-wider w-8">Sell:</span>
                                            <span class="text-zinc-900 dark:text-white font-semibold">${{ number_format($product->selling_price, 2) }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-col items-start space-y-0.5 whitespace-nowrap">
                                        @if($product->current_stock <= 0)
                                            <span class="inline-flex items-center gap-1 rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400 whitespace-nowrap">
                                                <span class="size-1 rounded-full bg-rose-600 dark:bg-rose-400"></span> Out of Stock
                                            </span>
                                        @elseif($product->isLowStock())
                                            <span class="inline-flex items-center gap-1 rounded bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/30 dark:text-amber-400 whitespace-nowrap">
                                                <span class="size-1 rounded-full bg-amber-600 dark:bg-amber-400"></span> Low ({{ $product->current_stock }})
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400 whitespace-nowrap">
                                                <span class="size-1 rounded-full bg-emerald-600 dark:bg-emerald-400"></span> {{ $product->current_stock }} In Stock
                                            </span>
                                        @endif
                                        <span class="text-[10px] text-zinc-400 whitespace-nowrap">Min Stock: {{ $product->minimum_stock }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if($product->status === 'active')
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">Active</span>
                                    @elseif($product->status === 'inactive')
                                        <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">Inactive</span>
                                    @else
                                        <span class="rounded bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">Discontinued</span>
                                    @endif
                                </td>
                                @if(!$isReadOnly)
                                    <td class="px-6 py-4 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <flux:button wire:click="openEditModal({{ $product->id }})" size="sm" icon="pencil-square" variant="ghost" aria-label="Edit" />
                                            <flux:button wire:click="confirmDelete({{ $product->id }})" size="sm" icon="trash" variant="ghost" aria-label="Delete" class="text-rose-600 hover:text-rose-700" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 6 + ($canBulkManage ? 1 : 0) + ($isSupplier ? 0 : 1) + ($isReadOnly ? 0 : 1) }}" class="px-6 py-16">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <flux:icon name="archive-box" class="size-12 text-zinc-300 dark:text-zinc-600 mb-4" />
                                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No Products Yet</flux:heading>
                                        <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">Add your first product to start tracking inventory.</flux:text>
                                        @if(!$isReadOnly)
                                            <flux:button variant="primary" size="sm" class="mt-4" wire:click="openCreateModal">Add Product</flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($products->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $products->links() }}
                </div>
            @endif
        </div>

        <!-- Add/Edit Modal -->
        @if($showFormModal)
            <flux:modal wire:model="showFormModal" class="w-full max-w-lg">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ $productId ? 'Edit Product details' : 'Add new Product' }}</flux:heading>
                        <flux:subheading>Update product specifications, categorization, prices, and alerting guidelines.</flux:subheading>
                    </div>

                    <form wire:submit.prevent="saveProduct" class="space-y-0" novalidate>
                        <div class="mb-5 p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-2">
                                <div>
                                    <flux:heading size="sm" class="font-bold">Product Identifier</flux:heading>
                                    <flux:text class="text-xs font-mono mt-1 text-zinc-500">{{ $identifier ?: 'Pending generation...' }}</flux:text>
                                </div>
                                <div class="w-full sm:w-48">
                                    <flux:select wire:model.live="previewFormat" size="sm" aria-label="Format">
                                        <option value="barcode">Code 128 Barcode</option>
                                        <option value="qr">QR Code</option>
                                    </flux:select>
                                </div>
                            </div>
                            
                            @if($identifier)
                                <div class="flex justify-center p-4 bg-white rounded border border-zinc-100 dark:border-zinc-800 text-black">
                                    @php
                                        $idService = app(\App\Services\ProductIdentificationService::class);
                                    @endphp
                                    @if($previewFormat === 'barcode')
                                        <div class="h-16 flex items-center justify-center overflow-hidden">
                                            {!! $idService->generateBarcodeSvg($identifier) !!}
                                        </div>
                                    @else
                                        <div class="size-32 flex items-center justify-center">
                                            {!! $idService->generateQrCodeSvg($identifier) !!}
                                        </div>
                                    @endif
                                </div>
                                @if($productId)
                                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                                        <flux:button href="{{ route('products.print-label', ['product' => $productId, 'type' => 'barcode']) }}" target="_blank" size="sm" icon="printer" variant="ghost">Barcode</flux:button>
                                        <flux:button href="{{ route('products.print-label', ['product' => $productId, 'type' => 'qr']) }}" target="_blank" size="sm" icon="qr-code" variant="ghost">QR Code</flux:button>
                                        <flux:button href="{{ route('products.print-label', ['product' => $productId, 'type' => 'both']) }}" target="_blank" size="sm" icon="document-duplicate" variant="ghost">Both</flux:button>
                                    </div>
                                @endif
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-x-4">
                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Unique SKU <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="sku" required placeholder="SKU-PRO-NAME" />
                                <flux:error name="sku" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>

                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Product Name <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="name" required placeholder="Mechanical Keyboard" />
                                <flux:error name="name" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        </div>

                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Description</flux:label>
                            <flux:textarea wire:model="description" placeholder="Optional specifications, dimensions, features" rows="3" />
                            <flux:error name="description" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>
                        
                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Product Image</flux:label>
                            <div class="space-y-3">
                                @if($image)
                                    <div class="relative inline-block">
                                        <img src="{{ $image->temporaryUrl() }}" class="size-24 rounded-lg object-contain bg-zinc-100 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700" alt="Preview" />
                                        <button type="button" wire:click="$set('image', null)" class="absolute -top-2 -right-2 size-5 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs hover:bg-rose-600">&times;</button>
                                    </div>
                                @elseif($existingImageUrl && !$removeImage)
                                    <div class="relative inline-block">
                                        <img src="{{ $existingImageUrl }}" class="size-24 rounded-lg object-contain bg-zinc-100 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700" alt="Current" />
                                        <button type="button" wire:click="removeProductImage" class="absolute -top-2 -right-2 size-5 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs hover:bg-rose-600">&times;</button>
                                    </div>
                                @endif
                                <div class="flex items-center gap-4 mt-2">
                                    <label class="cursor-pointer inline-flex items-center justify-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-700 shadow-sm ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700 dark:hover:bg-zinc-700 transition-colors focus-within:ring-2 focus-within:ring-indigo-500">
                                        <flux:icon name="arrow-up-tray" class="size-4" />
                                        <span>Upload Product Image</span>
                                        <input type="file" wire:model="image" accept="image/jpeg,image/png,image/webp" class="sr-only" />
                                    </label>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <div wire:loading.remove wire:target="image">
                                            @if($image)
                                                <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $image->getClientOriginalName() }}</span>
                                            @else
                                                JPG, PNG, WEBP (Max 2MB)
                                            @endif
                                        </div>
                                        <div wire:loading wire:target="image" class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                            <flux:icon name="arrow-path" class="size-3 animate-spin" />
                                            Uploading...
                                        </div>
                                    </div>
                                </div>
                                <flux:error name="image" class="!mt-0.5 text-xs font-medium" />
                            </div>
                        </flux:field>

                        <div class="grid grid-cols-2 gap-x-4">
                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Category <span class="text-rose-500">*</span></flux:label>
                                <flux:select wire:model="categoryId" required>
                                    @if($categories->isEmpty())
                                        <option value="" disabled selected>No categories available. Please create one first.</option>
                                    @else
                                        <option value="">Select Category</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    @endif
                                </flux:select>
                                <flux:error name="categoryId" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>

                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Supplier Partner <span class="text-rose-500">*</span></flux:label>
                                <flux:select wire:model="supplierId" required>
                                    @if($suppliers->isEmpty())
                                        <option value="" disabled selected>No suppliers available. Please create one first.</option>
                                    @else
                                        <option value="">Select Supplier</option>
                                        @foreach($suppliers as $supplier)
                                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                        @endforeach
                                    @endif
                                </flux:select>
                                <flux:error name="supplierId" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-3 gap-x-4">
                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Cost Price ($) <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="costPrice" type="number" step="0.01" min="0" required />
                                <flux:error name="costPrice" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>

                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Selling Price ($) <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="sellingPrice" type="number" step="0.01" min="0" required />
                                <flux:error name="sellingPrice" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>

                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Min. Alert Stock <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="minimumStock" type="number" min="0" required />
                                <flux:error name="minimumStock" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        </div>

                        <flux:field class="mb-5">
                            <flux:label class="mb-1">Status <span class="text-rose-500">*</span></flux:label>
                            <flux:select wire:model="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="discontinued">Discontinued</option>
                            </flux:select>
                            <flux:error name="status" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <div class="flex justify-end gap-3">
                            <flux:button wire:click="$set('showFormModal', false)" variant="ghost">Cancel</flux:button>
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="saveProduct">Save Changes</span>
                                <span wire:loading wire:target="saveProduct" class="flex items-center gap-2">
                                    <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                    Saving...
                                </span>
                            </flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>
        @endif

        <!-- Delete Confirmation Modal -->
        @if($showDeleteModal)
            <flux:modal wire:model="showDeleteModal">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Confirm Deletion</flux:heading>
                        <flux:subheading>Are you sure you want to delete this product profile? This action will fail if the product has transaction audit history logged.</flux:subheading>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="deleteProduct" variant="danger" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="deleteProduct">Delete Product</span>
                            <span wire:loading wire:target="deleteProduct" class="flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                Deleting...
                            </span>
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        @if($showImagePreviewModal)
            <flux:modal wire:model="showImagePreviewModal" class="w-full max-w-md">
                <div class="flex flex-col items-center gap-4">
                    <flux:heading size="lg">Product Image</flux:heading>
                    <img src="{{ $previewImageUrl }}" alt="Product preview" class="w-full max-h-96 object-contain rounded-lg" onerror="this.src='https://placehold.co/400x400/f4f4f5/a1a1aa?text=Image+Not+Found'" />
                    <flux:button wire:click="$set('showImagePreviewModal', false)" variant="ghost">Close</flux:button>
                </div>
            </flux:modal>
        @endif

        <!-- Bulk Update Modal -->
        @if($showBulkModal)
            <flux:modal wire:model="showBulkModal" class="w-full max-w-md">
                <div class="space-y-5">
                    <div>
                        <flux:heading size="lg">
                            @switch($bulkAction)
                                @case('category') Bulk update category @break
                                @case('supplier') Bulk update supplier @break
                                @case('minimum_stock') Bulk update minimum stock @break
                                @default Bulk update status
                            @endswitch
                        </flux:heading>
                        <flux:subheading>
                            This change is applied to the {{ $selectedCount }} selected {{ $selectedCount === 1 ? 'product' : 'products' }} and recorded in the audit trail.
                        </flux:subheading>
                    </div>

                    <form wire:submit.prevent="applyBulkAction" class="space-y-5" novalidate>
                        @if($bulkAction === 'category')
                            <flux:field>
                                <flux:label class="mb-1">New Category <span class="text-rose-500">*</span></flux:label>
                                <flux:select wire:model="bulkCategoryId" data-test="bulk-category-select">
                                    <option value="">Select Category</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="bulkCategoryId" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        @elseif($bulkAction === 'supplier')
                            <flux:field>
                                <flux:label class="mb-1">New Supplier <span class="text-rose-500">*</span></flux:label>
                                <flux:select wire:model="bulkSupplierId" data-test="bulk-supplier-select">
                                    <option value="">Select Supplier</option>
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="bulkSupplierId" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        @elseif($bulkAction === 'minimum_stock')
                            <flux:field>
                                <flux:label class="mb-1">New Minimum Stock Level <span class="text-rose-500">*</span></flux:label>
                                <flux:input wire:model="bulkMinimumStock" type="number" min="0" placeholder="10" data-test="bulk-minimum-stock-input" />
                                <flux:description>Low stock alerts are re-evaluated for every selected product.</flux:description>
                                <flux:error name="bulkMinimumStock" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        @else
                            <flux:field>
                                <flux:label class="mb-1">New Status <span class="text-rose-500">*</span></flux:label>
                                <flux:select wire:model="bulkStatus" data-test="bulk-status-select">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="discontinued">Discontinued</option>
                                </flux:select>
                                <flux:error name="bulkStatus" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>
                        @endif

                        <div class="flex justify-end gap-3">
                            <flux:button wire:click="$set('showBulkModal', false)" variant="ghost" type="button">Cancel</flux:button>
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="applyBulkAction">Apply to {{ $selectedCount }}</span>
                                <span wire:loading wire:target="applyBulkAction" class="flex items-center gap-2">
                                    <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                    Applying...
                                </span>
                            </flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>
        @endif

        <!-- Bulk Archive Confirmation -->
        @if($showBulkArchiveModal)
            <flux:modal wire:model="showBulkArchiveModal">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Archive {{ $selectedCount }} {{ $selectedCount === 1 ? 'product' : 'products' }}?</flux:heading>
                        <flux:subheading>
                            Archived products are soft deleted and can be restored by an administrator. Products with recorded inventory transactions are kept for audit integrity and reported back to you.
                        </flux:subheading>
                    </div>
                    @if($selectedSkus !== [])
                        <div class="max-h-32 overflow-y-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-950/50">
                            <flux:text class="block font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                {{ implode(', ', array_slice($selectedSkus, 0, 40)) }}{{ count($selectedSkus) > 40 ? ', …' : '' }}
                            </flux:text>
                        </div>
                    @endif
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showBulkArchiveModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="bulkArchive" variant="danger" wire:loading.attr="disabled" data-test="bulk-archive-confirm">
                            <span wire:loading.remove wire:target="bulkArchive">Archive Selected</span>
                            <span wire:loading wire:target="bulkArchive" class="flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                Archiving...
                            </span>
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        <!-- CSV Import Modal -->
        @if($showImportModal)
            <flux:modal wire:model="showImportModal" class="w-full max-w-2xl">
                <div class="space-y-5">
                    <div>
                        <flux:heading size="lg">Import products from CSV</flux:heading>
                        <flux:subheading>Rows are validated before anything is written. Valid rows are created in a single transaction; rejected rows are reported back with their row numbers.</flux:subheading>
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-950/50">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="space-y-1">
                                <flux:heading size="sm" class="font-bold">Required columns</flux:heading>
                                <flux:text class="block font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ implode(', ', $importColumns) }}</flux:text>
                                <flux:text class="block text-xs text-zinc-500">Optional: <span class="font-mono">{{ implode(', ', $importOptionalColumns) }}</span></flux:text>
                                <flux:text class="block text-xs text-zinc-500">Category and supplier are matched by name. Stock always starts at 0 and is moved through transactions.</flux:text>
                            </div>
                            <flux:button wire:click="downloadImportTemplate" size="sm" icon="document-arrow-down" class="shrink-0" data-test="download-template">Template</flux:button>
                        </div>
                    </div>

                    <div x-data="{ progress: 0, uploading: false }"
                         x-on:livewire-upload-start="uploading = true; progress = 0"
                         x-on:livewire-upload-finish="uploading = false; progress = 100"
                         x-on:livewire-upload-cancel="uploading = false"
                         x-on:livewire-upload-error="uploading = false"
                         x-on:livewire-upload-progress="progress = $event.detail.progress"
                         class="space-y-3">
                        <flux:field>
                            <flux:label class="mb-1">CSV file <span class="text-rose-500">*</span></flux:label>
                            <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-zinc-300 bg-white px-6 py-8 text-center transition-colors hover:border-blue-400 hover:bg-blue-50/40 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-blue-500 dark:hover:bg-blue-950/20 focus-within:ring-2 focus-within:ring-blue-500">
                                <flux:icon name="arrow-up-tray" class="size-6 text-zinc-400" />
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ $importFile ? $importFile->getClientOriginalName() : 'Choose a CSV file' }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">CSV up to 5 MB, max {{ number_format(\App\Services\ProductImportService::MAX_ROWS) }} rows</span>
                                <input type="file" wire:model="importFile" accept=".csv,text/csv,text/plain" class="sr-only" data-test="import-file" />
                            </label>
                            <flux:error name="importFile" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <div x-show="uploading" x-cloak class="space-y-1">
                            <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                <div class="h-full rounded-full bg-blue-600 transition-all dark:bg-blue-500" :style="`width: ${progress}%`"></div>
                            </div>
                            <flux:text class="text-xs text-zinc-500">Uploading… <span x-text="progress"></span>%</flux:text>
                        </div>

                        <div wire:loading wire:target="importProducts" class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 dark:border-blue-900/50 dark:bg-blue-950/30">
                            <flux:icon name="arrow-path" class="size-4 animate-spin text-blue-600 dark:text-blue-400" />
                            <flux:text class="text-xs font-medium text-blue-900 dark:text-blue-200">Validating rows and importing products…</flux:text>
                        </div>
                    </div>

                    @if($importSummary !== [])
                        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="import-summary">
                            @if(($importSummary['fatal_errors'] ?? []) !== [])
                                <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-900/50 dark:bg-rose-950/30">
                                    <flux:heading size="sm" class="font-bold text-rose-800 dark:text-rose-300">Import failed</flux:heading>
                                    <ul class="mt-1 list-inside list-disc space-y-0.5 text-xs text-rose-700 dark:text-rose-300">
                                        @foreach($importSummary['fatal_errors'] as $fatal)
                                            <li>{{ $fatal }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @else
                                <div class="grid grid-cols-3 gap-3 text-center">
                                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-950/50">
                                        <flux:text class="block text-lg font-bold text-zinc-900 dark:text-white">{{ $importSummary['total_rows'] }}</flux:text>
                                        <flux:text class="block text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Rows read</flux:text>
                                    </div>
                                    <div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
                                        <flux:text class="block text-lg font-bold text-emerald-700 dark:text-emerald-400" data-test="import-created">{{ $importSummary['imported'] }}</flux:text>
                                        <flux:text class="block text-[10px] font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-500">Created</flux:text>
                                    </div>
                                    <div class="rounded-lg bg-amber-50 p-3 dark:bg-amber-950/30">
                                        <flux:text class="block text-lg font-bold text-amber-700 dark:text-amber-400" data-test="import-skipped">{{ $importSummary['skipped'] }}</flux:text>
                                        <flux:text class="block text-[10px] font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-500">Skipped</flux:text>
                                    </div>
                                </div>
                            @endif

                            @if(($importSummary['row_errors'] ?? []) !== [])
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <flux:heading size="sm" class="font-bold">Row errors</flux:heading>
                                    <flux:button wire:click="downloadImportErrors" size="xs" icon="document-arrow-down" data-test="download-error-report">Download report</flux:button>
                                </div>
                                <div class="max-h-52 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                                    <table class="w-full text-left text-xs">
                                        <thead class="bg-zinc-50 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:bg-zinc-950">
                                            <tr>
                                                <th scope="col" class="px-3 py-2 w-16">Row</th>
                                                <th scope="col" class="px-3 py-2 w-32">SKU</th>
                                                <th scope="col" class="px-3 py-2">Errors</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                            @foreach(array_slice($importSummary['row_errors'], 0, 25) as $rowError)
                                                <tr>
                                                    <td class="px-3 py-2 font-mono text-zinc-500">{{ $rowError['row'] }}</td>
                                                    <td class="px-3 py-2 font-mono text-zinc-700 dark:text-zinc-300">{{ $rowError['sku'] ?: '-' }}</td>
                                                    <td class="px-3 py-2 text-rose-600 dark:text-rose-400">{{ implode(' ', $rowError['messages']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if(count($importSummary['row_errors']) > 25)
                                    <flux:text class="text-xs text-zinc-500">Showing the first 25 of {{ count($importSummary['row_errors']) }} rejected rows. Download the report for the full list.</flux:text>
                                @endif
                            @endif
                        </div>
                    @endif

                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showImportModal', false)" variant="ghost">Close</flux:button>
                        <flux:button wire:click="importProducts" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="importProducts, importFile" data-test="run-import">
                            <span wire:loading.remove wire:target="importProducts">Import Products</span>
                            <span wire:loading wire:target="importProducts" class="flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                Importing...
                            </span>
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
