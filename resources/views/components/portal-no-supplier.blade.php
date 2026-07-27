@props(['message' => 'Your account is not linked to a supplier yet.'])

<div {{ $attributes->class(['rounded-xl border border-dashed border-zinc-300 bg-white p-10 dark:border-zinc-700 dark:bg-zinc-900']) }}>
    <div class="flex flex-col items-center justify-center text-center">
        <flux:icon name="link-slash" class="mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
        <flux:heading size="lg" class="font-semibold text-zinc-700 dark:text-zinc-300">No supplier linked</flux:heading>
        <flux:text class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
            {{ $message }} Ask an administrator to link your user account to a supplier so your catalogue and orders can be shown.
        </flux:text>
    </div>
</div>
