<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-0" novalidate>
            @csrf
            <!-- Name -->
            <flux:field class="mb-4">
                <flux:label class="mb-1">{{ __('Name') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="name"
                    :value="old('name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :placeholder="__('Full name')"
                />
                <flux:error name="name" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <!-- Email Address -->
            <flux:field class="mb-4">
                <flux:label class="mb-1">{{ __('Email address') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="email"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <flux:error name="email" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <!-- Password -->
            <flux:field class="mb-4">
                <flux:label class="mb-1">{{ __('Password') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="password"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />
                <flux:error name="password" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <!-- Confirm Password -->
            <flux:field class="mb-6">
                <flux:label class="mb-1">{{ __('Confirm password') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Confirm password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />
                <flux:error name="password_confirmation" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
