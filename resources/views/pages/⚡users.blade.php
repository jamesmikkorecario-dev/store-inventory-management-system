<?php

use App\Models\User;
use App\Models\Supplier;
use Spatie\Permission\Models\Role;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Flux\Flux;

new #[Title('User Management')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterRole = '';
    public string $filterStatus = '';

    // Form fields
    public ?int $userId = null;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $roleName = '';
    public ?int $supplierId = null;
    public string $status = 'active';

    public bool $showFormModal = false;
    public bool $showDeleteModal = false;

    public function mount(): void
    {
        if (!Auth::user()->can('manage users')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterRole(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->resetForm();
        $user = User::findOrFail($id);
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->roleName = $user->roles->pluck('name')->first() ?? '';
        $this->supplierId = $user->supplier_id;
        $this->status = $user->status;

        $this->showFormModal = true;
    }

    public function saveUser(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . ($this->userId ?: 'NULL'),
            'roleName' => 'required|exists:roles,name',
            'supplierId' => 'required_if:roleName,Supplier|nullable|exists:suppliers,id',
            'status' => 'required|in:active,inactive',
        ];

        if (!$this->userId) {
            $rules['password'] = 'required|string|min:8';
        } else {
            $rules['password'] = 'nullable|string|min:8';
        }

        $validated = $this->validate($rules);

        if ($this->userId) {
            $user = User::findOrFail($this->userId);
            $user->fill([
                'name' => $this->name,
                'email' => $this->email,
                'supplier_id' => $this->roleName === 'Supplier' ? $this->supplierId : null,
                'status' => $this->status,
            ]);

            if ($this->password) {
                $user->password = Hash::make($this->password);
            }

            $user->save();
            $user->syncRoles([$this->roleName]);

            Flux::toast(variant: 'success', text: __('User updated successfully.'));
        } else {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'supplier_id' => $this->roleName === 'Supplier' ? $this->supplierId : null,
                'status' => $this->status,
            ]);

            $user->assignRole($this->roleName);

            Flux::toast(variant: 'success', text: __('User created successfully.'));
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->userId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteUser(): void
    {
        $user = User::findOrFail($this->userId);

        if ($user->id === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('You cannot delete your own account.'));
            $this->showDeleteModal = false;
            return;
        }

        $user->delete();

        Flux::toast(variant: 'success', text: __('User deleted successfully.'));
        $this->showDeleteModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->userId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->roleName = '';
        $this->supplierId = null;
        $this->status = 'active';
    }

    public function with(): array
    {
        $query = User::with(['roles', 'supplier']);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterRole) {
            $query->role($this->filterRole);
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return [
            'users' => $query->latest()->paginate(10),
            'roles' => Role::all(),
            'suppliers' => Supplier::where('status', 'active')->get(),
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-bold">User Management</flux:heading>
                <flux:subheading>Manage employee system logins, supplier accounts, and portal access permissions.</flux:subheading>
            </div>
            <flux:button wire:click="openCreateModal" variant="primary" icon="plus">Add User</flux:button>
        </div>

        <!-- Filters Bar -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex-1 max-w-sm">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name or email..." icon="magnifying-glass" />
            </div>
            <div class="flex flex-wrap gap-3">
                <flux:select wire:model.live="filterRole" class="min-w-[150px]">
                    <option value="">All Roles</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="filterStatus" class="min-w-[150px]">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </flux:select>
            </div>
        </div>

        <!-- Users Table -->
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th class="px-6 py-4">Name</th>
                            <th class="px-6 py-4">Email</th>
                            <th class="px-6 py-4">Role</th>
                            <th class="px-6 py-4">Supplier Firm</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($users as $user)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">{{ $user->name }}</td>
                                <td class="px-6 py-4">{{ $user->email }}</td>
                                <td class="px-6 py-4">
                                    @php $role = $user->roles->pluck('name')->first(); @endphp
                                    @if($role === 'Admin')
                                        <span class="rounded bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400">Admin</span>
                                    @elseif($role === 'Staff')
                                        <span class="rounded bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">Staff</span>
                                    @elseif($role === 'Supplier')
                                        <span class="rounded bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">Supplier</span>
                                    @else
                                        <span class="rounded bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">None</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-zinc-500">
                                    {{ $user->supplier->name ?? '-' }}
                                </td>
                                <td class="px-6 py-4">
                                    @if($user->status === 'active')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">
                                            <span class="size-1.5 rounded-full bg-emerald-600 dark:bg-emerald-400"></span> Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">
                                            <span class="size-1.5 rounded-full bg-rose-600 dark:bg-rose-400"></span> Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <flux:button wire:click="openEditModal({{ $user->id }})" size="sm" icon="pencil-square" variant="ghost" />
                                        @if($user->id !== auth()->id())
                                            <flux:button wire:click="confirmDelete({{ $user->id }})" size="sm" icon="trash" variant="ghost" class="text-rose-600 hover:text-rose-700" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-zinc-500">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($users->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $users->links() }}
                </div>
            @endif
        </div>

        <!-- Add/Edit Modal -->
        @if($showFormModal)
            <flux:modal wire:model="showFormModal" class="min-w-[500px]">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ $userId ? 'Edit User details' : 'Create new User' }}</flux:heading>
                        <flux:subheading>Provide the user profile details, credentials, and system role access.</flux:subheading>
                    </div>

                    <form wire:submit.prevent="saveUser" class="space-y-4" novalidate>
                        <flux:input wire:model="name" label="Name" required placeholder="Full Name" />
                        <flux:input wire:model="email" label="Email Address" type="email" required placeholder="email@example.com" />
                        
                        <flux:input wire:model="password" label="{{ $userId ? 'Password (Leave empty to keep current)' : 'Password' }}" type="password" placeholder="Min. 8 characters" :required="!$userId" />

                        <flux:select wire:model.live="roleName" label="Access Role" required>
                            <option value="">Select Role</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->name }}">{{ $role->name }}</option>
                            @endforeach
                        </flux:select>

                        @if($roleName === 'Supplier')
                            <flux:select wire:model="supplierId" label="Linked Supplier Firm" required>
                                <option value="">Select Supplier</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </flux:select>
                        @endif

                        <flux:select wire:model="status" label="Status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </flux:select>

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
                        <flux:subheading>Are you sure you want to delete this user account? This will revoke their access immediately. The account can be restored by system administrators if needed.</flux:subheading>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="deleteUser" variant="danger">Delete User</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
