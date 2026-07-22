<?php

use App\Models\Category;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;

new #[Title('Category Management')] class extends Component {
    use WithPagination;

    public string $search = '';

    // Form fields
    public ?int $categoryId = null;
    public string $name = '';
    public string $description = '';

    public bool $showFormModal = false;
    public bool $showDeleteModal = false;
    public bool $isReadOnly = true;

    public function mount(): void
    {
        $user = Auth::user();
        if (!$user->can('view categories')) {
            abort(403, 'Unauthorized.');
        }

        $this->isReadOnly = !$user->can('manage categories');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        if ($this->isReadOnly) abort(403);
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        if ($this->isReadOnly) abort(403);
        $this->resetForm();
        $category = Category::findOrFail($id);
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->description = $category->description ?? '';

        $this->showFormModal = true;
    }

    public function saveCategory(): void
    {
        if ($this->isReadOnly) abort(403);

        $rules = [
            'name' => 'required|string|max:255|unique:categories,name,' . ($this->categoryId ?: 'NULL'),
            'description' => 'nullable|string|max:1000',
        ];

        $validated = $this->validate($rules);

        if ($this->categoryId) {
            $category = Category::findOrFail($this->categoryId);
            $category->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
            Flux::toast(variant: 'success', text: __('Category updated successfully.'));
        } else {
            Category::create([
                'name' => $this->name,
                'description' => $this->description,
            ]);
            Flux::toast(variant: 'success', text: __('Category created successfully.'));
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        if ($this->isReadOnly) abort(403);
        $this->categoryId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteCategory(): void
    {
        if ($this->isReadOnly) abort(403);

        $category = Category::findOrFail($this->categoryId);

        // Prevent soft deleting if category has products
        if ($category->products()->count() > 0) {
            Flux::toast(
                variant: 'danger', 
                text: __('Cannot delete category. There are ' . $category->products()->count() . ' products cataloged under it.')
            );
            $this->showDeleteModal = false;
            return;
        }

        $category->delete();

        Flux::toast(variant: 'success', text: __('Category deleted successfully.'));
        $this->showDeleteModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->categoryId = null;
        $this->name = '';
        $this->description = '';
    }

    public function with(): array
    {
        $query = Category::withCount('products');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('description', 'like', '%' . $this->search . '%');
            });
        }

        return [
            'categories' => $query->latest()->paginate(10),
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">Category Management</flux:heading>
                <flux:subheading>Manage stock categories to classify products, structure catalog filters, and filter reports.</flux:subheading>
            </div>
            @if(!$isReadOnly)
                <flux:button wire:click="openCreateModal" variant="primary" icon="plus">Add Category</flux:button>
            @endif
        </div>

        <!-- Search Bar -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex-1 max-w-sm">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, description..." icon="magnifying-glass" />
            </div>
        </div>

        <!-- Categories Table -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th class="px-6 py-4">Category Name</th>
                            <th class="px-6 py-4">Description</th>
                            <th class="px-6 py-4">Seeded Products</th>
                            @if(!$isReadOnly)
                                <th class="px-6 py-4 text-right">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($categories as $category)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">{{ $category->name }}</td>
                                <td class="px-6 py-4 max-w-[400px] truncate" title="{{ $category->description }}">
                                    {{ $category->description ?: '-' }}
                                </td>
                                <td class="px-6 py-4">
                                    <span class="rounded bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $category->products_count }} product(s)
                                    </span>
                                </td>
                                @if(!$isReadOnly)
                                    <td class="px-6 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <flux:button wire:click="openEditModal({{ $category->id }})" size="sm" icon="pencil-square" variant="ghost" />
                                            <flux:button wire:click="confirmDelete({{ $category->id }})" size="sm" icon="trash" variant="ghost" class="text-rose-600 hover:text-rose-700" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-zinc-500">No categories found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($categories->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $categories->links() }}
                </div>
            @endif
        </div>

        <!-- Add/Edit Modal -->
        @if($showFormModal)
            <flux:modal wire:model="showFormModal" class="min-w-[500px]">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ $categoryId ? 'Edit Category details' : 'Add new Category' }}</flux:heading>
                        <flux:subheading>Manage stock categories to classify system inventory items.</flux:subheading>
                    </div>

                    <form wire:submit.prevent="saveCategory" class="space-y-4" novalidate>
                        <flux:input wire:model="name" label="Category Name" required placeholder="Electronics, Stationery" />
                        <flux:textarea wire:model="description" label="Description" placeholder="Optional description detailing what products belong to this category" rows="4" />

                        <div class="flex justify-end gap-3 mt-6">
                            <flux:button wire:click="$set('showFormModal', false)" variant="ghost">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Save Changes</flux:button>
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
                        <flux:subheading>Are you sure you want to delete this category? This action will fail if the category contains active products.</flux:subheading>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="deleteCategory" variant="danger">Delete Category</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
