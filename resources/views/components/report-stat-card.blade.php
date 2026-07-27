@props([
    'label',
    'value',
    'icon' => 'chart-bar',
    'iconClass' => 'text-indigo-500',
    'hint' => null,
    'hintClass' => 'text-zinc-500',
])

<div {{ $attributes->class(['flex flex-col justify-between overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900']) }}>
    <div class="flex items-start justify-between gap-3">
        <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
        <flux:icon :name="$icon" class="size-6 shrink-0 {{ $iconClass }}" />
    </div>
    <div class="mt-4 flex flex-wrap items-baseline justify-between gap-1">
        <flux:heading size="lg" class="font-bold text-zinc-900 dark:text-white">{{ $value }}</flux:heading>
        @if($hint)
            <span class="text-xs {{ $hintClass }}">{{ $hint }}</span>
        @endif
    </div>
</div>
