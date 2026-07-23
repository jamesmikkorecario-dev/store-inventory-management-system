<?php

use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Flux\Flux;

new #[Title('Product Management')] class extends Component {
    use WithPagination, WithFileUploads;

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

    // Permissions
    public bool $isReadOnly = true;
    public bool $isSupplier = false;
    public ?int $userSupplierId = null;

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
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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
    }

    public function updatedFilterSupplier(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStockStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
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

    public function with(): array
    {
        $query = Product::with(['category', 'supplier']);

        // Supplier role: Can only view their own assigned products
        if ($this->isSupplier) {
            $query->where('supplier_id', $this->userSupplierId);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku', 'like', '%' . $this->search . '%')
                  ->orWhere('identifier', 'like', '%' . $this->search . '%')
                  ->orWhere('description', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterCategory) {
            $query->where('category_id', $this->filterCategory);
        }

        if ($this->filterSupplier && !$this->isSupplier) {
            $query->where('supplier_id', $this->filterSupplier);
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterStockStatus) {
            if ($this->filterStockStatus === 'low') {
                $query->whereColumn('current_stock', '<=', 'minimum_stock');
            } elseif ($this->filterStockStatus === 'instock') {
                $query->where('current_stock', '>', 0);
            } elseif ($this->filterStockStatus === 'out') {
                $query->where('current_stock', '=', 0);
            }
        }

        return [
            'products' => $query->latest()->paginate(10),
            'categories' => Category::all(),
            'suppliers' => Supplier::where('status', 'active')->get(),
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
            @if(!$isReadOnly)
                <flux:button wire:click="openCreateModal" variant="primary" icon="plus">Add Product</flux:button>
            @endif
        </div>

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
                            <th scope="col" class="px-4 py-4 w-[72px]"></th>
                            <th scope="col" class="pl-0 pr-6 py-4 w-[25%]">SKU / Product Name</th>
                            <th scope="col" class="px-6 py-4 w-[15%]">Category</th>
                            @if(!$isSupplier)
                                <th scope="col" class="px-6 py-4 w-[15%]">Supplier</th>
                            @endif
                            <th scope="col" class="px-6 py-4 w-[15%]">Prices (Cost / Selling)</th>
                            <th scope="col" class="px-6 py-4 w-[15%]">Stock Level</th>
                            <th scope="col" class="px-6 py-4 w-[10%]">Status</th>
                            @if(!$isReadOnly)
                                <th scope="col" class="px-6 py-4 text-right w-[5%]">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($products as $product)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-4 py-4">
                                    <button type="button" wire:click="openImagePreview('{{ $product->imageUrl() }}')" class="block size-10 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:ring-2 hover:ring-blue-500 transition-all cursor-pointer">
                                        <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" class="size-full object-cover" loading="lazy" onerror="this.src='https://placehold.co/400x400/f4f4f5/a1a1aa?text={{ urlencode(Str::limit($product->name, 10)) }}'" />
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
                                    <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
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
                                <td class="px-6 py-4">
                                    @if($product->status === 'active')
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">Active</span>
                                    @elseif($product->status === 'inactive')
                                        <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">Inactive</span>
                                    @else
                                        <span class="rounded bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">Discontinued</span>
                                    @endif
                                </td>
                                @if(!$isReadOnly)
                                    <td class="px-6 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <flux:button wire:click="openEditModal({{ $product->id }})" size="sm" icon="pencil-square" variant="ghost" />
                                            <flux:button wire:click="confirmDelete({{ $product->id }})" size="sm" icon="trash" variant="ghost" class="text-rose-600 hover:text-rose-700" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-16">
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
                                        <img src="{{ $image->temporaryUrl() }}" class="size-24 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" alt="Preview" />
                                        <button type="button" wire:click="$set('image', null)" class="absolute -top-2 -right-2 size-5 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs hover:bg-rose-600">&times;</button>
                                    </div>
                                @elseif($existingImageUrl && !$removeImage)
                                    <div class="relative inline-block">
                                        <img src="{{ $existingImageUrl }}" class="size-24 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" alt="Current" />
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
                                    <option value="">Select Category</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="categoryId" class="!mt-0.5 text-xs font-medium" />
                            </flux:field>

                            <flux:field class="mb-4">
                                <flux:label class="mb-1">Supplier Partner <span class="text-rose-500">*</span></flux:label>
                                <flux:select wire:model="supplierId" required>
                                    <option value="">Select Supplier</option>
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                    @endforeach
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
    </div>
