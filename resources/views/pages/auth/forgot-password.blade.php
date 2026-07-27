<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-0" novalidate>
            @csrf

            <!-- Email Address -->
            <flux:field class="mb-5">
                <flux:label class="mb-1">{{ __('Email address') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="email"
                    type="email"
                    required
                    autofocus
                    placeholder="email@example.com"
                />
                <flux:error name="email" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
