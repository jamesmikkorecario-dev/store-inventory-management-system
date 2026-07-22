<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-0" novalidate>
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:field class="mb-4">
                <flux:label class="mb-1">{{ __('Email') }} <span class="text-rose-500">*</span></flux:label>
                <flux:input
                    name="email"
                    value="{{ request('email') }}"
                    type="email"
                    required
                    autocomplete="email"
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
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
