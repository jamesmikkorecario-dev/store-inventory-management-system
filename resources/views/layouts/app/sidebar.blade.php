<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    @if(auth()->user()->can('view products') || auth()->user()->hasRole('Supplier'))
                        <flux:sidebar.item icon="archive-box" :href="route('products.index')" :current="request()->routeIs('products.index')" wire:navigate>
                            {{ __('Products') }}
                        </flux:sidebar.item>
                    @endif

                    @if(auth()->user()->can('view transactions') || auth()->user()->hasRole('Supplier'))
                        <flux:sidebar.item icon="arrows-right-left" :href="route('transactions.index')" :current="request()->routeIs('transactions.index')" wire:navigate>
                            {{ __('Transactions') }}
                        </flux:sidebar.item>
                    @endif

                    @can('view suppliers')
                        <flux:sidebar.item icon="truck" :href="route('suppliers.index')" :current="request()->routeIs('suppliers.index')" wire:navigate>
                            {{ __('Suppliers') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('view categories')
                        <flux:sidebar.item icon="tag" :href="route('categories.index')" :current="request()->routeIs('categories.index')" wire:navigate>
                            {{ __('Categories') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('view reports')
                        <flux:sidebar.item icon="arrow-trending-up" :href="route('reports.index')" :current="request()->routeIs('reports.index')" wire:navigate>
                            {{ __('Reports') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('manage users')
                        <flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.index')" wire:navigate>
                            {{ __('Users') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('view audit trail')
                        <flux:sidebar.item icon="clock" :href="route('audit.index')" :current="request()->routeIs('audit.index')" wire:navigate>
                            {{ __('Audit Trail') }}
                        </flux:sidebar.item>
                    @endcan

                    <flux:sidebar.item icon="bell" :href="route('alerts.index')" :current="request()->routeIs('alerts.index')" wire:navigate>
                        {{ __('Alerts') }}
                        <livewire:sidebar-alerts-badge />
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <div class="flex items-center gap-2">
                <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
            </div>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
