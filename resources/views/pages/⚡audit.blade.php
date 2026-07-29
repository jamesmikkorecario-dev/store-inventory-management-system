<?php

use Spatie\Activitylog\Models\Activity;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;

new #[Title('Audit Trail')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterEvent = '';
    public string $filterSubject = '';

    public function mount(): void
    {
        // View authorization handled by route middleware
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterEvent(): void
    {
        $this->resetPage();
    }

    public function updatedFilterSubject(): void
    {
        $this->resetPage();
    }

    public function formatChanges($properties): string
    {
        if (empty($properties)) return '-';
        
        $output = '';
        $props = is_array($properties) ? $properties : json_decode($properties, true);

        if (!$props) return '-';

        // Update event
        if (isset($props['attributes']) && isset($props['old'])) {
            $output .= '<ul class="space-y-1 text-xs list-none p-0 m-0">';
            foreach ($props['attributes'] as $key => $value) {
                if (in_array($key, ['updated_at', 'created_at', 'password'])) continue;
                
                $oldVal = $props['old'][$key] ?? 'NULL';
                $newVal = $value ?? 'NULL';

                if (is_array($oldVal)) $oldVal = json_encode($oldVal);
                if (is_array($newVal)) $newVal = json_encode($newVal);
                if (is_bool($oldVal)) $oldVal = $oldVal ? 'true' : 'false';
                if (is_bool($newVal)) $newVal = $newVal ? 'true' : 'false';

                $safeKey = e($key);
                $safeOldVal = e((string) $oldVal);
                $safeNewVal = e((string) $newVal);

                $output .= "<li><span class='font-mono font-semibold text-zinc-500'>{$safeKey}</span>: <span class='text-rose-600 line-through bg-rose-50 px-1 rounded dark:bg-rose-950/20'>{$safeOldVal}</span> &rarr; <span class='text-emerald-600 bg-emerald-50 px-1 rounded font-medium dark:bg-emerald-950/20'>{$safeNewVal}</span></li>";
            }
            $output .= '</ul>';
        } 
        // Create / Delete event
        elseif (isset($props['attributes'])) {
            $output .= '<ul class="space-y-1 text-xs list-none p-0 m-0">';
            foreach ($props['attributes'] as $key => $value) {
                if (in_array($key, ['updated_at', 'created_at', 'password'])) continue;
                
                $newVal = $value ?? 'NULL';
                if (is_array($newVal)) $newVal = json_encode($newVal);
                if (is_bool($newVal)) $newVal = $newVal ? 'true' : 'false';

                $safeKey = e($key);
                $safeNewVal = e((string) $newVal);

                $output .= "<li><span class='font-mono font-semibold text-zinc-500'>{$safeKey}</span>: <span class='text-emerald-600 bg-emerald-50 px-1 rounded font-medium dark:bg-emerald-950/20'>{$safeNewVal}</span></li>";
            }
            $output .= '</ul>';
        }

        return $output ?: '-';
    }

    public function with(): array
    {
        $query = Activity::with(['causer']);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('description', 'like', '%' . $this->search . '%')
                  ->orWhere('event', 'like', '%' . $this->search . '%')
                  ->orWhereHas('causer', function ($uq) {
                      $uq->where('name', 'like', '%' . $this->search . '%');
                  });
            });
        }

        if ($this->filterEvent) {
            $query->where('event', $this->filterEvent);
        }

        if ($this->filterSubject) {
            $query->where('subject_type', 'like', '%' . $this->filterSubject);
        }

        return [
            'activities' => $query->latest()->paginate(15),
            'subjectTypes' => [
                'User' => 'User Profile changes',
                'Supplier' => 'Supplier Partner changes',
                'Category' => 'Product Category changes',
                'Product' => 'Product catalog changes',
                'InventoryTransaction' => 'Inventory Transaction adjustments',
            ],
        ];
    }
}; ?>

    <div class="space-y-6">
        <!-- Heading -->
        <div>
            <flux:heading size="xl" class="font-bold">System Audit Trail</flux:heading>
            <flux:subheading>Monitor system-wide logins, data modifications, and permission shifts.</flux:subheading>
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end bg-white dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex-1 relative">
                <flux:input wire:model.live.debounce.300ms="search" label="Search Audit Log" placeholder="Search causer user, event description..." icon="magnifying-glass" />
                <div wire:loading wire:target="search" class="absolute right-3 top-9">
                    <flux:icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
                </div>
            </div>
            <div class="w-full sm:w-64">
                <flux:select wire:model.live="filterSubject" label="Subject">
                    <option value="">All Subjects</option>
                    @foreach($subjectTypes as $class => $label)
                        <option value="{{ $class }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="w-full sm:w-48">
                <flux:select wire:model.live="filterEvent" label="Event">
                    <option value="">All Events</option>
                    <option value="created">Created</option>
                    <option value="updated">Updated</option>
                    <option value="deleted">Deleted</option>
                </flux:select>
            </div>
        </div>

        <!-- Audit Table -->
        <div wire:loading wire:target="search, filterEvent, filterSubject, sortBy, gotoPage, nextPage, previousPage" class="flex justify-center py-4 w-full">
            <flux:icon name="arrow-path" class="size-5 animate-spin text-zinc-400" />
        </div>
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" wire:loading.class="opacity-50 pointer-events-none" wire:target="search, filterEvent, filterSubject, sortBy, gotoPage, nextPage, previousPage">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-400 dark:border-zinc-800 dark:bg-zinc-950">
                            <th scope="col" class="px-6 py-4 w-px whitespace-nowrap">Timestamp</th>
                            <th scope="col" class="px-6 py-4 w-px whitespace-nowrap">Causer User</th>
                            <th scope="col" class="px-6 py-4 w-px whitespace-nowrap">Event Action</th>
                            <th scope="col" class="px-6 py-4 w-px whitespace-nowrap">Subject (Entity)</th>
                            <th scope="col" class="px-6 py-4 w-full">Attribute Changes (Old &rarr; New)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($activities as $act)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300 font-medium whitespace-nowrap">
                                    {{ $act->created_at->format('Y-m-d H:i:s') }}
                                    <flux:text class="block text-[10px] text-zinc-400">{{ $act->created_at->diffForHumans() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-semibold text-zinc-900 dark:text-white">
                                        {{ $act->causer->name ?? 'System Process' }}
                                    </flux:text>
                                    <flux:text class="block text-[10px] text-zinc-400">
                                        {{ $act->causer->email ?? 'CRON/Automated' }}
                                    </flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($act->event === 'created')
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">Created</span>
                                    @elseif($act->event === 'updated')
                                        <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400">Updated</span>
                                    @elseif($act->event === 'deleted')
                                        <span class="rounded bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-400">Deleted</span>
                                    @else
                                        <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">{{ strtoupper($act->event) }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">
                                        {{ class_basename($act->subject_type) ?: 'None' }}
                                    </flux:text>
                                    <flux:text class="block text-xs text-zinc-400">
                                        ID: {{ $act->subject_id ?: '-' }}
                                    </flux:text>
                                </td>
                                <td class="px-6 py-4">
                                    {!! $this->formatChanges($act->properties) !!}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-16">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <flux:icon name="shield-check" class="size-12 text-zinc-300 dark:text-zinc-600 mb-4" />
                                        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No Audit Logs Yet</flux:heading>
                                        <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">Activity logs will appear here once actions are performed.</flux:text>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($activities->hasPages())
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $activities->links() }}
                </div>
            @endif
        </div>
    </div>
