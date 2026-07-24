<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-0" novalidate>
            @csrf

            <!-- Email Address -->
            <flux:field class="mb-4">
                <flux:label class="mb-1">{{ __('Email address') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="email"
                    :value="old('email')"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <flux:error name="email" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <!-- Password -->
            <flux:field class="mb-4">
                <div class="flex justify-between items-baseline mb-1">
                    <flux:label>{{ __('Password') }} <span class="text-rose-500">*</span></flux:label>
                    @if (Route::has('password.request'))
                        <flux:link class="text-xs" :href="route('password.request')" wire:navigate>
                            {{ __('Forgot your password?') }}
                        </flux:link>
                    @endif
                </div>
                <flux:input
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />
                <flux:error name="password" class="!mt-0.5 text-xs font-medium" />
            </flux:field>

            <!-- Remember Me -->
            <div class="flex items-center gap-2 mb-6">
                <flux:checkbox name="remember" id="remember" :checked="old('remember')" />
                <flux:label for="remember" class="!mb-0 cursor-pointer">{{ __('Remember me') }}</flux:label>
            </div>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
