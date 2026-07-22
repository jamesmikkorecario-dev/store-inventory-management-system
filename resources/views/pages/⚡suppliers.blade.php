<?php

use App\Models\Supplier;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;

new #[Title('Supplier Management')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterStatus = '';

    // Form fields
    public ?int $supplierId = null;
    public string $name = '';
    public string $contactPerson = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $status = 'active';

    public bool $showFormModal = false;
    public bool $showDeleteModal = false;
    public bool $isReadOnly = true;

    public function mount(): void
    {
        $user = Auth::user();
        if (!$user->can('view suppliers')) {
            abort(403, 'Unauthorized.');
        }

        $this->isReadOnly = !$user->can('manage suppliers');
    }

    public function updatedSearch(): void
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
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        if ($this->isReadOnly) abort(403);
        $this->resetForm();
        $supplier = Supplier::findOrFail($id);
        $this->supplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->contactPerson = $supplier->contact_person ?? '';
        $this->email = $supplier->email ?? '';
        $this->phone = $supplier->phone ?? '';
        $this->address = $supplier->address ?? '';
        $this->status = $supplier->status;

        $this->showFormModal = true;
    }

    public function saveSupplier(): void
    {
        if ($this->isReadOnly) abort(403);

        $rules = [
            'name' => 'required|string|max:255',
            'contactPerson' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:suppliers,email,' . ($this->supplierId ?: 'NULL'),
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ];

        $messages = [
            'name.required' => 'Company name is required.',
            'email.email' => 'Email address is invalid.',
            'email.unique' => 'Email address has already been taken.',
            'status.required' => 'Status is required.',
        ];

        $validated = $this->validate($rules, $messages);

        if ($this->supplierId) {
            $supplier = Supplier::findOrFail($this->supplierId);
            $supplier->update([
                'name' => $this->name,
                'contact_person' => $this->contactPerson,
                'email' => $this->email,
                'phone' => $this->phone,
                'address' => $this->address,
                'status' => $this->status,
            ]);
            Flux::toast(variant: 'success', text: __('Supplier updated successfully.'));
        } else {
            Supplier::create([
                'name' => $this->name,
                'contact_person' => $this->contactPerson,
                'email' => $this->email,
                'phone' => $this->phone,
                'address' => $this->address,
                'status' => $this->status,
            ]);
            Flux::toast(variant: 'success', text: __('Supplier created successfully.'));
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        if ($this->isReadOnly) abort(403);
        $this->supplierId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteSupplier(): void
    {
        if ($this->isReadOnly) abort(403);

        $supplier = Supplier::findOrFail($this->supplierId);

        // Prevent soft deleting if supplier has active products assigned
        if ($supplier->products()->count() > 0) {
            Flux::toast(
                variant: 'danger', 
                text: __('Cannot delete supplier. They are assigned to ' . $supplier->products()->count() . ' products.')
            );
            $this->showDeleteModal = false;
            return;
        }

        $supplier->delete();

        Flux::toast(variant: 'success', text: __('Supplier deleted successfully.'));
        $this->showDeleteModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->supplierId = null;
        $this->name = '';
        $this->contactPerson = '';
        $this->email = '';
        $this->phone = '';
        $this->address = '';
        $this->status = 'active';
    }

    public function with(): array
    {
        $query = Supplier::withCount('products');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('contact_person', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return [
            'suppliers' => $query->latest()->paginate(10),
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">Supplier Management</flux:heading>
                <flux:subheading>Manage suppliers, contact info, and their catalog status indicators.</flux:subheading>
            </div>
            @if(!$isReadOnly)
                <flux:button wire:click="openCreateModal" variant="primary" icon="plus">Add Supplier</flux:button>
            @endif
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex-1 max-w-sm">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, contact, email..." icon="magnifying-glass" />
            </div>
            <div class="flex gap-3">
                <flux:select wire:model.live="filterStatus" class="min-w-[150px]">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </flux:select>
            </div>
        </div>

        <!-- Suppliers List -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th class="px-6 py-4">Company Name</th>
                            <th class="px-6 py-4">Contact Person</th>
                            <th class="px-6 py-4">Email</th>
                            <th class="px-6 py-4">Phone</th>
                            <th class="px-6 py-4">Products Seeded</th>
                            <th class="px-6 py-4">Status</th>
                            @if(!$isReadOnly)
                                <th class="px-6 py-4 text-right">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($suppliers as $supplier)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-6 py-4">
                                    <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $supplier->name }}</flux:text>
                                    <flux:text class="block text-xs text-zinc-400 max-w-[220px] truncate" title="{{ $supplier->address }}">{{ $supplier->address ?: 'No address' }}</flux:text>
                                </td>
                                <td class="px-6 py-4">{{ $supplier->contact_person ?: '-' }}</td>
                                <td class="px-6 py-4">{{ $supplier->email ?: '-' }}</td>
                                <td class="px-6 py-4">{{ $supplier->phone ?: '-' }}</td>
                                <td class="px-6 py-4">
                                    <span class="rounded bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $supplier->products_count }} product(s)
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    @if($supplier->status === 'active')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">
                                            <span class="size-1.5 rounded-full bg-emerald-600 dark:bg-emerald-400"></span> Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">
                                            <span class="size-1.5 rounded-full bg-rose-600 dark:bg-rose-400"></span> Inactive
                                        </span>
                                    @endif
                                </td>
                                @if(!$isReadOnly)
                                    <td class="px-6 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <flux:button wire:click="openEditModal({{ $supplier->id }})" size="sm" icon="pencil-square" variant="ghost" />
                                            <flux:button wire:click="confirmDelete({{ $supplier->id }})" size="sm" icon="trash" variant="ghost" class="text-rose-600 hover:text-rose-700" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-zinc-500">No suppliers found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($suppliers->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $suppliers->links() }}
                </div>
            @endif
        </div>

        <!-- Add/Edit Modal -->
        @if($showFormModal)
            <flux:modal wire:model="showFormModal" class="w-full max-w-lg">
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ $supplierId ? 'Edit Supplier details' : 'Add new Supplier' }}</flux:heading>
                        <flux:subheading>Manage supplier organization profiles and billing contacts.</flux:subheading>
                    </div>

                    <form wire:submit.prevent="saveSupplier" class="space-y-0" novalidate>
                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Company Name <span class="text-rose-500">*</span></flux:label>
                            <flux:input wire:model="name" required placeholder="Apex Logistics LLC" />
                            <flux:error name="name" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Contact Person</flux:label>
                            <flux:input wire:model="contactPerson" placeholder="John Doe" />
                            <flux:error name="contactPerson" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Contact Email</flux:label>
                            <flux:input wire:model="email" type="email" placeholder="sales@supplier.com" />
                            <flux:error name="email" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Phone Number</flux:label>
                            <flux:input wire:model="phone" placeholder="+1 (555) 123-4567" />
                            <flux:error name="phone" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field class="mb-4">
                            <flux:label class="mb-1">Postal Address</flux:label>
                            <flux:textarea wire:model="address" placeholder="Street, City, Zip Code" rows="3" />
                            <flux:error name="address" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <flux:field class="mb-5">
                            <flux:label class="mb-1">Status <span class="text-rose-500">*</span></flux:label>
                            <flux:select wire:model="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </flux:select>
                            <flux:error name="status" class="!mt-0.5 text-xs font-medium" />
                        </flux:field>

                        <div class="flex justify-end gap-3">
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
                        <flux:subheading>Are you sure you want to delete this supplier profile? This action will fail if the supplier currently supplies products cataloged in the system.</flux:subheading>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="deleteSupplier" variant="danger">Delete Supplier</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
