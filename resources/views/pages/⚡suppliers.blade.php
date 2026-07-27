<?php

use App\Models\Supplier;
use App\Services\CatalogExporter;
use App\Services\CatalogFilters;
use App\Services\CatalogService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
    #[Locked]
    public bool $isReadOnly = true;

    #[Locked]
    public bool $canExportCatalog = false;

    public function mount(): void
    {
        $user = Auth::user();
        // View authorization handled by route middleware
        $this->isReadOnly = !$user->can('manage suppliers');
        $this->canExportCatalog = !$user->hasRole('Supplier') && $user->can('export catalog');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[On('suppliers-updated')]
    public function refreshData(): void
    {
        // Component will re-render
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
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^\+[1-9]\d{6,14}$/'],
            'address' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ];

        $messages = [
            'name.required' => 'Company name is required.',
            'email.email' => 'Email address is invalid.',
            'email.unique' => 'Email address has already been taken.',
            'phone.regex' => 'The phone number format is invalid. It must be in international E.164 format (e.g. +639171234567).',
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
            $this->dispatch('suppliers-updated');
            $this->dispatch('products-updated');
            $this->dispatch('dashboard-updated');
            Flux::toast(variant: 'success', text: 'Updated successfully.');
        } else {
            Supplier::create([
                'name' => $this->name,
                'contact_person' => $this->contactPerson,
                'email' => $this->email,
                'phone' => $this->phone,
                'address' => $this->address,
                'status' => $this->status,
            ]);
            $this->dispatch('suppliers-updated');
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
        $this->supplierId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteSupplier(): void
    {
        if ($this->isReadOnly) abort(403);

        $supplier = Supplier::findOrFail($this->supplierId);

        // Prevent soft deleting if supplier has active products assigned
        if ($supplier->products()->withTrashed()->count() > 0) {
            Flux::toast(
                variant: 'danger', 
                text: __('Cannot delete supplier. They are assigned to ' . $supplier->products()->withTrashed()->count() . ' products.')
            );
            $this->showDeleteModal = false;
            return;
        }

        $supplier->delete();

        $this->dispatch('suppliers-updated');
        $this->dispatch('products-updated');
        $this->dispatch('dashboard-updated');

        Flux::toast(variant: 'success', text: 'Deleted successfully.');
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
        return [
            'suppliers' => app(CatalogService::class)->supplierQuery($this->filters())->paginate(10),
        ];
    }

    /**
     * Export the currently filtered supplier list.
     */
    public function exportCsv(): StreamedResponse
    {
        $user = Auth::user();

        abort_unless(
            $this->canExportCatalog && $user !== null && !$user->hasRole('Supplier') && $user->can('export catalog'),
            403
        );

        return app(CatalogExporter::class)->csv('suppliers', $this->filters());
    }

    /**
     * Current filter state, shared by the table and the CSV export.
     */
    protected function filters(): CatalogFilters
    {
        return CatalogFilters::fromArray([
            'search' => $this->search,
            'status' => $this->filterStatus,
        ]);
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">Supplier Management</flux:heading>
                <flux:subheading>Manage suppliers, contact info, and their catalog status indicators.</flux:subheading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canExportCatalog)
                    <flux:button wire:click="exportCsv" icon="arrow-down-tray" size="sm" class="w-full sm:w-auto" data-test="export-suppliers">Export CSV</flux:button>
                @endif
                @if(!$isReadOnly)
                    <flux:button wire:click="openCreateModal" variant="primary" icon="plus" size="sm" class="w-full sm:w-auto">Add Supplier</flux:button>
                @endif
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex-1 relative">
                <flux:input wire:model.live.debounce.300ms="search" label="Search Supplier" placeholder="Search by name, contact, email..." icon="magnifying-glass" />
                <div wire:loading wire:target="search" class="absolute right-3 top-9">
                    <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
                </div>
            </div>
            <div class="w-full sm:w-64">
                <flux:select wire:model.live="filterStatus" label="Status">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </flux:select>
            </div>
        </div>

        <!-- Suppliers List -->
        <div wire:loading wire:target="search, filterStatus, sortBy, gotoPage, nextPage, previousPage" class="flex justify-center py-4 w-full">
            <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
        </div>
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:loading.class="opacity-50 pointer-events-none" wire:target="search, filterStatus, sortBy, gotoPage, nextPage, previousPage">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-6 py-4">Company Name</th>
                            <th scope="col" class="px-6 py-4">Contact Person</th>
                            <th scope="col" class="px-6 py-4">Email</th>
                            <th scope="col" class="px-6 py-4">Phone</th>
                            <th scope="col" class="px-6 py-4">Linked Products</th>
                            <th scope="col" class="px-6 py-4">Status</th>
                            @if(!$isReadOnly)
                                <th scope="col" class="px-6 py-4 text-left">Actions</th>
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
                                            <flux:button wire:click="openEditModal({{ $supplier->id }})" size="sm" icon="pencil-square" variant="ghost" aria-label="Edit" />
                                            <flux:button wire:click="confirmDelete({{ $supplier->id }})" size="sm" icon="trash" variant="ghost" aria-label="Delete" class="text-rose-600 hover:text-rose-700" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-16">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <flux:icon name="truck" class="size-12 text-zinc-300 dark:text-zinc-600 mb-4" />
                                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No Suppliers Yet</flux:heading>
                                        <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">Add a supplier to start managing your supply chain.</flux:text>
                                        @if(!$isReadOnly)
                                            <flux:button variant="primary" size="sm" class="mt-4" wire:click="openCreateModal">Add Supplier</flux:button>
                                        @endif
                                    </div>
                                </td>
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
                            <div x-data="{
                                phoneVal: @entangle('phone'),
                                itiInstance: null,
                                init() {
                                    const input = this.$refs.phoneInput;
                                    this.itiInstance = window.intlTelInput(input, {
                                        initialCountry: 'ph',
                                        countrySearch: true,
                                        strictMode: true,
                                        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/js/utils.js'
                                    });

                                    if (this.phoneVal) {
                                        this.itiInstance.setNumber(this.phoneVal);
                                    }

                                    input.addEventListener('input', () => {
                                        if (typeof intlTelInputUtils !== 'undefined') {
                                            const current = this.itiInstance.getNumber(intlTelInputUtils.numberFormat.INTERNATIONAL);
                                            if (current) {
                                                this.itiInstance.setNumber(current);
                                            }
                                        }
                                        this.phoneVal = this.itiInstance.getNumber() || '';
                                    });

                                    this.$watch('phoneVal', (value) => {
                                        if (this.itiInstance && value !== this.itiInstance.getNumber()) {
                                            this.itiInstance.setNumber(value || '');
                                        }
                                    });

                                    this.$cleanup(() => {
                                        if (this.itiInstance) {
                                            this.itiInstance.destroy();
                                            this.itiInstance = null;
                                        }
                                    });
                                }
                            }" class="w-full" wire:ignore>
                                <input x-ref="phoneInput" type="tel" placeholder="917 123 4567" />
                            </div>
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
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="saveSupplier">Save Changes</span>
                                <span wire:loading wire:target="saveSupplier" class="flex items-center gap-2">
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
                        <flux:subheading>Are you sure you want to delete this supplier profile? This action will fail if the supplier currently supplies products cataloged in the system.</flux:subheading>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="deleteSupplier" variant="danger" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="deleteSupplier">Delete Supplier</span>
                            <span wire:loading wire:target="deleteSupplier" class="flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                Deleting...
                            </span>
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
