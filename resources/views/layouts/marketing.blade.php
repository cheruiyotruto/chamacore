@php
    $nav = [
        ['label' => 'Home', 'route' => 'home', 'pattern' => 'home'],
        ['label' => 'Features', 'route' => 'marketing.features', 'pattern' => 'marketing.features'],
        ['label' => 'Join a group', 'route' => 'public.join.index', 'pattern' => 'public.join.*'],
        ['label' => 'Pricing', 'route' => 'marketing.pricing', 'pattern' => 'marketing.pricing'],
        ['label' => 'About', 'route' => 'marketing.about', 'pattern' => 'marketing.about'],
        ['label' => 'Contact', 'route' => 'marketing.contact', 'pattern' => 'marketing.contact'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'Chama Core helps chamas, SACCOs, welfare groups and organisations run savings, loans, payments and reports in one secure system.')">
    <meta property="og:title" content="@yield('title', config('app.name'))">
    <meta property="og:description" content="@yield('description', 'One trusted system for group finances, members and decisions.')">
    <meta name="theme-color" content="#18181b">
    <title>@yield('title', config('app.name')) · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink: {
                            800: '#3f3f46',
                            900: '#27272a',
                            950: '#18181b',
                        },
                        ocean: {
                            50: '#fafafa',
                            100: '#f4f4f5',
                            200: '#e4e4e7',
                            300: '#d4d4d8',
                            400: '#a1a1aa',
                            500: '#71717a',
                            600: '#3f3f46',
                            700: '#27272a',
                            800: '#1f1f23',
                            900: '#09090b',
                        },
                        jade: {
                            50: '#fafafa',
                            100: '#f4f4f5',
                            200: '#e4e4e7',
                            300: '#d4d4d8',
                            400: '#d4d4d8',
                            500: '#a1a1aa',
                            600: '#71717a',
                            700: '#52525b',
                            800: '#3f3f46',
                            900: '#27272a',
                        },
                        sun: {
                            50: '#fafafa',
                            100: '#f4f4f5',
                            400: '#d4d4d8',
                            500: '#a1a1aa',
                        },
                        coral: {
                            50: '#fafafa',
                            100: '#f4f4f5',
                            500: '#a1a1aa',
                            600: '#71717a',
                            800: '#52525b',
                            900: '#3f3f46',
                        },
                        brand: {
                            50: '#fafafa',
                            100: '#f4f4f5',
                            200: '#e4e4e7',
                            500: '#71717a',
                            600: '#3f3f46',
                            700: '#27272a',
                        },
                    },
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    boxShadow: {
                        lift: '0 24px 60px -24px rgba(24, 24, 27, 0.35)',
                        card: '0 12px 35px -18px rgba(24, 24, 27, 0.25)',
                    },
                },
            },
        };
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
    <style>
        body { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        [x-cloak] { display: none !important; }
        .grid-noise {
            background-image: linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px);
            background-size: 44px 44px;
        }
    </style>
    @stack('head')
</head>
<body class="min-h-full bg-white text-ink-900 antialiased">
    <div x-data="{ mobileOpen: false }" class="relative">
        <header class="sticky top-0 z-50 border-b border-white/10 bg-ink-900/90 backdrop-blur-lg">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-white" @click="mobileOpen = false">
                    <span class="relative flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-ocean-400 to-jade-400 text-sm font-extrabold text-ink-950 shadow-lg shadow-ocean-900/30">
                        C
                        <span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-sun-400 ring-2 ring-ink-900"></span>
                    </span>
                    <span class="text-lg font-bold tracking-tight">{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-1 lg:flex" aria-label="Main navigation">
                    @foreach ($nav as $item)
                        <a href="{{ route($item['route']) }}"
                           class="rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs($item['pattern']) ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="hidden items-center gap-2 lg:flex">
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-200 hover:text-white">Sign in</a>
                    <a href="{{ route('marketing.contact') }}" class="inline-flex items-center gap-2 rounded-lg bg-jade-500 px-4 py-2 text-sm font-bold text-ink-950 shadow-sm transition hover:bg-jade-400 focus:outline-none focus:ring-2 focus:ring-jade-300 focus:ring-offset-2 focus:ring-offset-ink-900">
                        Request access
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>

                <button type="button"
                        class="rounded-lg p-2 text-slate-200 hover:bg-white/10 lg:hidden"
                        x-bind:aria-expanded="mobileOpen ? 'true' : 'false'"
                        aria-controls="marketing-mobile-nav"
                        aria-label="Toggle navigation"
                        @click="mobileOpen = !mobileOpen">
                    <x-icon name="menu" class="h-6 w-6" x-cloak x-show="!mobileOpen" />
                    <x-icon name="x" class="h-6 w-6" x-cloak x-show="mobileOpen" />
                </button>
            </div>

            <div id="marketing-mobile-nav" x-cloak x-show="mobileOpen" x-transition class="border-t border-white/10 bg-ink-950/95 lg:hidden">
                <nav class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-3 sm:px-6" aria-label="Mobile navigation">
                    @foreach ($nav as $item)
                        <a href="{{ route($item['route']) }}"
                           class="rounded-lg px-3 py-2.5 text-sm font-medium {{ request()->routeIs($item['pattern']) ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}"
                           @click="mobileOpen = false">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                    <div class="mt-2 grid grid-cols-2 gap-2 border-t border-white/10 pt-3">
                        <a href="{{ route('login') }}" class="rounded-lg border border-white/20 px-3 py-2 text-center text-sm font-semibold text-white">Sign in</a>
                        <a href="{{ route('marketing.contact') }}" class="rounded-lg bg-jade-500 px-3 py-2 text-center text-sm font-bold text-ink-950">Request access</a>
                    </div>
                </nav>
            </div>
        </header>

        <main>
            @if (session('status'))
                <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex items-start gap-3 rounded-xl border border-jade-200 bg-jade-50 px-4 py-3 text-sm text-jade-700">
                        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" />
                        <p>{{ session('status') }}</p>
                    </div>
                </div>
            @endif

            @if (session('error') || $errors->any())
                <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex items-start gap-3 rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-600">
                        <x-icon name="x" class="mt-0.5 h-4 w-4 shrink-0" />
                        <p>{{ session('error') ?? 'Please check the highlighted fields and try again.' }}</p>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="mt-24 bg-ink-950 text-slate-300">
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <div class="flex items-center gap-2.5 text-white">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-ocean-400 to-jade-400 text-sm font-extrabold text-ink-950">C</span>
                            <span class="text-lg font-bold">{{ config('app.name') }}</span>
                        </div>
                        <p class="mt-4 max-w-sm text-sm leading-6 text-slate-400">
                            One dependable workspace for group savings, lending, payments, records and decisions.
                        </p>
                        <div class="mt-5 flex flex-wrap gap-2 text-xs font-medium text-slate-400">
                            <span class="rounded-full bg-white/5 px-3 py-1 ring-1 ring-white/10">Tenant isolated</span>
                            <span class="rounded-full bg-white/5 px-3 py-1 ring-1 ring-white/10">Double-entry ledger</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-bold text-white">Product</p>
                        <ul class="mt-4 space-y-2.5 text-sm">
                            <li><a href="{{ route('marketing.features') }}" class="hover:text-white">Features</a></li>
                            <li><a href="{{ route('marketing.pricing') }}" class="hover:text-white">Plans &amp; pricing</a></li>
                            <li><a href="{{ route('marketing.features') }}#payments" class="hover:text-white">Payments</a></li>
                            <li><a href="{{ route('marketing.features') }}#reports" class="hover:text-white">Reports</a></li>
                        </ul>
                    </div>

                    <div>
                        <p class="text-sm font-bold text-white">Company</p>
                        <ul class="mt-4 space-y-2.5 text-sm">
                            <li><a href="{{ route('marketing.about') }}" class="hover:text-white">About Chama Core</a></li>
                            <li><a href="{{ route('marketing.about') }}#principles" class="hover:text-white">How we work</a></li>
                            <li><a href="{{ route('marketing.contact') }}" class="hover:text-white">Contact</a></li>
                            <li><a href="{{ route('login') }}" class="hover:text-white">Existing member sign in</a></li>
                        </ul>
                    </div>

                    <div>
                        <p class="text-sm font-bold text-white">Get started</p>
                        <p class="mt-4 text-sm leading-6 text-slate-400">Tell us about your group and we will help you choose the right setup.</p>
                        <a href="{{ route('marketing.contact') }}" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-jade-500 px-4 py-2 text-sm font-bold text-ink-950 hover:bg-jade-400">
                            Request access
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    </div>
                </div>

                <div class="mt-12 flex flex-col gap-3 border-t border-white/10 pt-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                    <p>&copy; {{ now()->year }} {{ config('app.name') }}. Built for responsible group finance.</p>
                    <p>Savings · Loans · Payments · Governance · Reports</p>
                </div>
            </div>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
