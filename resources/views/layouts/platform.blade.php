@php
    $user = auth()->user();
    $links = [
        ['label' => 'Overview', 'route' => 'platform.dashboard', 'pattern' => 'platform.dashboard', 'icon' => 'chart'],
        ['label' => 'Access requests', 'route' => 'platform.leads.index', 'pattern' => 'platform.leads.*', 'icon' => 'chat'],
        ['label' => 'Applications', 'route' => 'platform.applications.index', 'pattern' => 'platform.applications.*', 'icon' => 'users'],
        ['label' => 'Groups', 'route' => 'platform.groups.index', 'pattern' => 'platform.groups.*', 'icon' => 'users'],
        ['label' => 'Plans', 'route' => 'platform.plans.index', 'pattern' => 'platform.plans.*', 'icon' => 'receipt'],
        ['label' => 'Payment charges', 'route' => 'platform.charges.index', 'pattern' => 'platform.charges.*', 'icon' => 'receipt'],
        ['label' => 'Currencies', 'route' => 'platform.currencies.index', 'pattern' => 'platform.currencies.*', 'icon' => 'banknotes'],
        ['label' => 'Managed revenue', 'route' => 'platform.revenue.index', 'pattern' => 'platform.revenue.*', 'icon' => 'chart'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Platform administration') · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    @include('partials.appearance', ['theme' => \App\Support\Appearance::for(null)])

    @stack('head')
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <header class="border-b border-slate-800 bg-slate-900 text-white">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3" aria-label="Go to the public landing page">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-400 text-sm font-bold text-slate-900">C</span>
                <div>
                    <p class="text-sm font-semibold">{{ config('app.name') }}</p>
                    <p class="text-xs text-slate-400">Platform administration</p>
                </div>
            </a>

            <div class="flex items-center gap-4">
                <span class="hidden text-xs text-slate-400 sm:block">{{ $user?->email }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-200 hover:bg-slate-800">Sign out</button>
                </form>
            </div>
        </div>

        <nav class="border-t border-slate-800">
            <div class="mx-auto flex max-w-7xl gap-1 px-4 lg:px-8">
                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}"
                       class="flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs($link['pattern']) ? 'border-brand-400 text-white' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                        <x-icon :name="$link['icon']" class="h-4 w-4" />
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 py-8 lg:px-8">
        @if (session('status'))
            <div class="mb-5 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ session('status') }}</p>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-5 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <x-icon name="x" class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ session('error') }}</p>
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
