<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>
            @keyframes fade-in {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .animate-fade-in {
                animation: fade-in 1s ease-out forwards;
            }
            .animate-fade-in-delayed {
                opacity: 0;
                animation: fade-in 1s ease-out 0.2s forwards;
            }
            .animate-fade-in-delayed-more {
                opacity: 0;
                animation: fade-in 1s ease-out 0.4s forwards;
            }
            .animate-fade-in-delayed-most {
                opacity: 0;
                animation: fade-in 1s ease-out 0.6s forwards;
            }
            html {
                scroll-behavior: smooth;
            }
        </style>
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-sans antialiased selection:bg-indigo-500 selection:text-white">

        <!-- 1. Sticky Navigation Bar -->
        <header class="sticky top-0 z-50 w-full border-b border-zinc-200 dark:border-zinc-800 bg-white/80 dark:bg-zinc-900/80 backdrop-blur">
            <div class="container mx-auto px-4 h-16 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="size-8 rounded bg-indigo-600 flex items-center justify-center text-white font-bold">
                        S
                    </div>
                    <span class="font-bold text-xl tracking-tight">SIMS</span>
                </div>
                
                <div class="flex items-center gap-4">
                    <a href="#" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors" title="GitHub">
                        <flux:icon name="code-bracket" class="size-5" />
                    </a>
                    @if (Route::has('login'))
                        @auth
                            <flux:button href="{{ route('dashboard') }}" variant="primary">Dashboard</flux:button>
                        @else
                            <flux:button href="{{ route('login') }}" variant="ghost">Log in</flux:button>
                            @if (Route::has('register'))
                                <flux:button href="{{ route('register') }}" variant="primary">Get Started</flux:button>
                            @endif
                        @endauth
                    @endif
                </div>
            </div>
        </header>

        <main>
            <!-- 2. Hero Section -->
            <section class="relative pt-24 pb-32 overflow-hidden">
                <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-900/20 via-zinc-900 to-zinc-900 dark:from-indigo-900/40 dark:via-zinc-900 dark:to-zinc-900"></div>
                <div class="container mx-auto px-4 text-center">
                    <flux:heading level="1" class="text-5xl md:text-6xl font-extrabold tracking-tight mb-6 animate-fade-in">
                        Store Inventory <br class="hidden md:block"/> Management System
                    </flux:heading>
                    <flux:text class="text-xl md:text-2xl text-zinc-600 dark:text-zinc-400 max-w-2xl mx-auto mb-10 animate-fade-in-delayed">
                        Streamline your inventory operations with precision. A powerful, modern solution for managing products, suppliers, and transactions in real-time.
                    </flux:text>
                    
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4 animate-fade-in-delayed-more">
                        @if (Route::has('login'))
                            @auth
                                <flux:button href="{{ route('dashboard') }}" variant="primary" class="w-full sm:w-auto px-6 py-2.5 text-base">Go to Dashboard</flux:button>
                            @else
                                <flux:button href="{{ route('register') }}" variant="primary" class="w-full sm:w-auto px-6 py-2.5 text-base">Get Started</flux:button>
                                <flux:button href="{{ route('login') }}" variant="outline" class="w-full sm:w-auto px-6 py-2.5 text-base">Log in</flux:button>
                            @endauth
                        @endif
                        <flux:button href="#" variant="ghost" class="w-full sm:w-auto px-6 py-2.5 text-base" icon="code-bracket">View GitHub</flux:button>
                    </div>
                </div>
            </section>

            <!-- 4. Dashboard Preview Section -->
            <section class="container mx-auto px-4 pb-24 -mt-12">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950 p-2 shadow-2xl relative animate-fade-in-delayed-most group">
                    <div class="absolute -inset-0.5 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-xl blur opacity-30 group-hover:opacity-50 transition duration-1000"></div>
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden relative shadow-inner h-[500px] flex flex-col">
                        <!-- Mock Header -->
                        <div class="h-14 border-b border-zinc-200 dark:border-zinc-800 flex items-center px-6 bg-zinc-50/50 dark:bg-zinc-900/50">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full bg-red-500"></div>
                                <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                                <div class="w-3 h-3 rounded-full bg-green-500"></div>
                            </div>
                        </div>
                        <!-- Mock Body -->
                        <div class="flex flex-1 p-6 gap-6">
                            <!-- Sidebar -->
                            <div class="w-48 hidden md:flex flex-col gap-4">
                                <div class="h-8 bg-zinc-200 dark:bg-zinc-800 rounded-md"></div>
                                <div class="h-8 bg-zinc-100 dark:bg-zinc-800/50 rounded-md"></div>
                                <div class="h-8 bg-zinc-100 dark:bg-zinc-800/50 rounded-md"></div>
                                <div class="h-8 bg-zinc-100 dark:bg-zinc-800/50 rounded-md"></div>
                            </div>
                            <!-- Content -->
                            <div class="flex-1 flex flex-col gap-6">
                                <!-- Stats -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="h-24 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-500/20 rounded-lg p-4 flex flex-col justify-between">
                                        <div class="h-4 w-24 bg-indigo-200 dark:bg-indigo-800 rounded"></div>
                                        <div class="h-8 w-16 bg-indigo-300 dark:bg-indigo-700 rounded"></div>
                                    </div>
                                    <div class="h-24 bg-zinc-50 dark:bg-zinc-800/30 border border-zinc-200 dark:border-zinc-800 rounded-lg p-4 flex flex-col justify-between">
                                        <div class="h-4 w-24 bg-zinc-200 dark:bg-zinc-700 rounded"></div>
                                        <div class="h-8 w-16 bg-zinc-300 dark:bg-zinc-600 rounded"></div>
                                    </div>
                                    <div class="h-24 bg-zinc-50 dark:bg-zinc-800/30 border border-zinc-200 dark:border-zinc-800 rounded-lg p-4 flex flex-col justify-between">
                                        <div class="h-4 w-24 bg-zinc-200 dark:bg-zinc-700 rounded"></div>
                                        <div class="h-8 w-16 bg-zinc-300 dark:bg-zinc-600 rounded"></div>
                                    </div>
                                </div>
                                <!-- Table -->
                                <div class="flex-1 border border-zinc-200 dark:border-zinc-800 rounded-lg p-4 bg-white dark:bg-zinc-900/50">
                                    <div class="h-6 w-32 bg-zinc-200 dark:bg-zinc-800 rounded mb-4"></div>
                                    <div class="space-y-3">
                                        <div class="h-10 bg-zinc-100 dark:bg-zinc-800/50 rounded"></div>
                                        <div class="h-10 bg-zinc-100 dark:bg-zinc-800/50 rounded"></div>
                                        <div class="h-10 bg-zinc-100 dark:bg-zinc-800/50 rounded"></div>
                                        <div class="h-10 bg-zinc-100 dark:bg-zinc-800/50 rounded"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 5. Technology Stack -->
            <section class="py-16 border-y border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/30">
                <div class="container mx-auto px-4 text-center">
                    <flux:heading level="3" class="text-zinc-500 dark:text-zinc-400 text-sm font-semibold tracking-wider uppercase mb-8">Powered By Modern Tech</flux:heading>
                    <div class="flex flex-wrap justify-center gap-4">
                        <flux:badge color="zinc">Laravel 12</flux:badge>
                        <flux:badge color="zinc">Livewire 4</flux:badge>
                        <flux:badge color="zinc">Flux UI</flux:badge>
                        <flux:badge color="zinc">Tailwind CSS v4</flux:badge>
                        <flux:badge color="zinc">MySQL</flux:badge>
                        <flux:badge color="zinc">Pest</flux:badge>
                        <flux:badge color="zinc">GitHub Actions</flux:badge>
                    </div>
                </div>
            </section>

            <!-- 3. Features Grid -->
            <section class="py-24">
                <div class="container mx-auto px-4">
                    <div class="text-center mb-16">
                        <flux:heading level="2" class="text-3xl font-bold mb-4">Everything you need to manage your store</flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-400 max-w-2xl mx-auto">A comprehensive suite of tools designed to handle every aspect of your inventory workflow.</flux:text>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        @php
                            $features = [
                                ['icon' => 'chart-bar-square', 'title' => 'Inventory Tracking', 'desc' => 'Monitor stock levels in real-time and prevent shortages.'],
                                ['icon' => 'archive-box', 'title' => 'Product Management', 'desc' => 'Easily add, edit, and organize your product catalog.'],
                                ['icon' => 'truck', 'title' => 'Supplier Management', 'desc' => 'Keep track of vendors, orders, and delivery schedules.'],
                                ['icon' => 'arrows-right-left', 'title' => 'Transaction Management', 'desc' => 'Record inbound and outbound stock movements effortlessly.'],
                                ['icon' => 'tag', 'title' => 'Categories', 'desc' => 'Group products into customizable categories for quick access.'],
                                ['icon' => 'arrow-trending-up', 'title' => 'Reports', 'desc' => 'Generate actionable insights from your inventory data.'],
                                ['icon' => 'shield-check', 'title' => 'Audit Trail', 'desc' => 'Maintain a secure log of all user activities and changes.'],
                                ['icon' => 'users', 'title' => 'User Management', 'desc' => 'Control access with role-based permissions and secure authentication.'],
                            ];
                        @endphp

                        @foreach($features as $feature)
                            <flux:card class="group hover:-translate-y-1 transition-transform duration-300">
                                <div class="size-12 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                                    <flux:icon name="{{ $feature['icon'] }}" class="size-6" />
                                </div>
                                <flux:heading level="3" class="text-lg font-semibold mb-2">{{ $feature['title'] }}</flux:heading>
                                <flux:text class="text-zinc-600 dark:text-zinc-400 text-sm">{{ $feature['desc'] }}</flux:text>
                            </flux:card>
                        @endforeach
                    </div>
                </div>
            </section>

            <!-- 6. Why This Project Section -->
            <section class="py-24 bg-zinc-50 dark:bg-zinc-900/50 border-t border-zinc-200 dark:border-zinc-800">
                <div class="container mx-auto px-4">
                    <div class="max-w-3xl mx-auto">
                        <div class="text-center mb-12">
                            <flux:heading level="2" class="text-3xl font-bold mb-4">Why This Project?</flux:heading>
                            <flux:text class="text-zinc-600 dark:text-zinc-400">This is a showcase portfolio project demonstrating modern web development practices.</flux:text>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-6">
                            <ul class="space-y-4">
                                <li class="flex items-start gap-3">
                                    <flux:icon name="check-circle" variant="solid" class="size-6 text-green-500 shrink-0" />
                                    <div>
                                        <strong class="block text-zinc-900 dark:text-white">Robust Architecture</strong>
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Clean architecture using Laravel's latest features.</span>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <flux:icon name="check-circle" variant="solid" class="size-6 text-green-500 shrink-0" />
                                    <div>
                                        <strong class="block text-zinc-900 dark:text-white">Auth & Authorization</strong>
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Secure user management and role-based access.</span>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <flux:icon name="check-circle" variant="solid" class="size-6 text-green-500 shrink-0" />
                                    <div>
                                        <strong class="block text-zinc-900 dark:text-white">Complex Workflows</strong>
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Handling real-world inventory transactions.</span>
                                    </div>
                                </li>
                            </ul>
                            <ul class="space-y-4">
                                <li class="flex items-start gap-3">
                                    <flux:icon name="check-circle" variant="solid" class="size-6 text-green-500 shrink-0" />
                                    <div>
                                        <strong class="block text-zinc-900 dark:text-white">Audit Logging</strong>
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Tracking every change for accountability.</span>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <flux:icon name="check-circle" variant="solid" class="size-6 text-green-500 shrink-0" />
                                    <div>
                                        <strong class="block text-zinc-900 dark:text-white">Modern UI/UX</strong>
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Responsive, accessible interface powered by Flux UI.</span>
                                    </div>
                                </li>
                                <li class="flex items-start gap-3">
                                    <flux:icon name="check-circle" variant="solid" class="size-6 text-green-500 shrink-0" />
                                    <div>
                                        <strong class="block text-zinc-900 dark:text-white">Testing & CI/CD</strong>
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Comprehensive test coverage with Pest and automated deployments.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- 7. Footer -->
        <footer class="border-t border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 py-12">
            <div class="container mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-2">
                    <div class="size-6 rounded bg-indigo-600 flex items-center justify-center text-white font-bold text-xs">
                        S
                    </div>
                    <span class="font-semibold">SIMS</span>
                </div>
                
                <div class="flex items-center gap-6 text-sm text-zinc-600 dark:text-zinc-400">
                    <a href="#" class="hover:text-zinc-900 dark:hover:text-white transition-colors">Repository</a>
                    <a href="#" class="hover:text-zinc-900 dark:hover:text-white transition-colors">Documentation</a>
                    <span>License: MIT</span>
                </div>

                <div class="text-sm text-zinc-500 dark:text-zinc-500">
                    &copy; {{ date('Y') }} James Mikko Recario. All rights reserved.
                </div>
            </div>
        </footer>
    </body>
</html>
