@php
    $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
    $group = $currentGroup ?? $ctx->group();
    $member = $currentMembership ?? $ctx->membership();

    $sectionKeys = [
        'portal.savings.index' => 'savings',
        'portal.investments.index' => 'investments',
        'portal.loans.index' => 'loans',
        'portal.wallet.show' => 'wallet',
        'portal.on-behalf.index' => 'wallet',
        'portal.bills.index' => 'bills',
        'portal.bank-transfers.index' => 'bank_transfers',
        'portal.float.index' => 'float_purchases',
        'portal.mgr.index' => 'mgr',
        'portal.documents.index' => 'documents',
        'portal.governance.index' => 'governance',
        'portal.support.index' => 'support',
        'portal.invitations.index' => 'invitations',
    ];

    $links = [
        ['label' => 'Overview', 'route' => 'portal.dashboard', 'pattern' => 'portal.dashboard', 'icon' => 'home'],
        ['label' => 'Savings', 'route' => 'portal.savings.index', 'pattern' => 'portal.savings.*', 'icon' => 'banknotes'],
        ['label' => 'Investments', 'route' => 'portal.investments.index', 'pattern' => 'portal.investments.*', 'icon' => 'trending-up'],
        ['label' => 'Loans', 'route' => 'portal.loans.index', 'pattern' => 'portal.loans.*', 'icon' => 'credit-card'],
        ['label' => 'Wallet', 'route' => 'portal.wallet.show', 'pattern' => 'portal.wallet.*', 'icon' => 'wallet'],
        ['label' => 'Bills', 'route' => 'portal.bills.index', 'pattern' => 'portal.bills.*', 'icon' => 'receipt'],
        ['label' => 'Bank transfers', 'route' => 'portal.bank-transfers.index', 'pattern' => 'portal.bank-transfers.*', 'icon' => 'banknotes'],
        ['label' => 'Buy M-Pesa float', 'route' => 'portal.float.index', 'pattern' => 'portal.float.*', 'icon' => 'wallet'],
        ['label' => 'Merry-go-round', 'route' => 'portal.mgr.index', 'pattern' => 'portal.mgr.*', 'icon' => 'calendar'],
        ['label' => 'Documents', 'route' => 'portal.documents.index', 'pattern' => 'portal.documents.*', 'icon' => 'folder'],
        ['label' => 'Governance', 'route' => 'portal.governance.index', 'pattern' => 'portal.governance.*', 'icon' => 'scale'],
        ['label' => 'Support', 'route' => 'portal.support.index', 'pattern' => 'portal.support.*', 'icon' => 'chat'],
        ['label' => 'Invite', 'route' => 'portal.invitations.index', 'pattern' => 'portal.invitations.*', 'icon' => 'users'],
    ];

    if (\App\Support\OnBehalfPayment::enabled($group)) {
        $links[] = ['label' => 'Pay for a member', 'route' => 'portal.on-behalf.index', 'pattern' => 'portal.on-behalf.*', 'icon' => 'users'];
    }

    $links = array_values(array_filter($links, function (array $link) use ($group, $member, $sectionKeys) {
        $section = $sectionKeys[$link['route']] ?? null;

        if (! $section) {
            return true; // dashboard/overview
        }

        return \App\Support\PortalAccess::canAccessSection($group, $member, $section);
    }));

    $mobileLinks = array_slice($links, 0, 5);

    $activeSection = collect($links)->first(
        fn ($link) => $link['route'] !== 'portal.dashboard' && request()->routeIs($link['pattern'])
    );
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'My account') · {{ $group?->name ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    @include('partials.appearance', ['theme' => \App\Support\Appearance::for($group)])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
    @stack('head')
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-4">
            <a href="{{ route('portal.dashboard') }}" class="flex min-w-0 items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">C</span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-900">{{ $group?->name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $member?->member_no }} · {{ $member?->full_name }}</p>
                </div>
            </a>

            <div class="flex items-center gap-1.5">
                @if ($unreadNotifications ?? false)
                    <a href="{{ route('notifications.index') }}" class="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Notifications">
                        <x-icon name="bell" />
                        <span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white">{{ $unreadNotifications }}</span>
                    </a>
                @endif

                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-full p-0.5 hover:bg-slate-100">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $member?->initials() }}</span>
                    </button>
                    <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                        <p class="truncate px-2 pb-2 text-sm font-medium text-slate-900">{{ $member?->full_name }}</p>
                        <a href="{{ route('profile.edit') }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Profile &amp; security</a>
                        <a href="{{ route('mfa.setup') }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Two-factor authentication</a>
                        @unless ($ctx->isMember())
                            <a href="{{ route('dashboard') }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Organisation portal</a>
                        @endunless
                        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-100 pt-1">
                            @csrf
                            <button type="submit" class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-rose-600 hover:bg-rose-50">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <nav class="border-t border-slate-100">
            <div class="mx-auto flex max-w-6xl items-center gap-2 px-4 py-2">
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button"
                            @click="open = !open"
                            x-bind:aria-expanded="open"
                            class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-base font-bold transition {{ request()->routeIs('portal.dashboard') ? 'bg-brand-50 text-brand-700' : 'text-slate-800 hover:bg-slate-100 hover:text-slate-900' }}">
                        <x-icon name="home" class="h-5 w-5" />
                        <span>Dashboard</span>
                        @if ($activeSection)
                            <span class="text-slate-300">/</span>
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-brand-50 px-2 py-0.5 text-sm font-bold text-brand-700">
                                <x-icon :name="$activeSection['icon']" class="h-4 w-4" />
                                {{ $activeSection['label'] }}
                            </span>
                        @endif
                        <x-icon name="chevron-down" class="h-4 w-4 text-slate-500 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
                    </button>

                    <div x-cloak
                         x-show="open"
                         x-transition.origin.top.left
                         class="absolute left-0 z-30 mt-2 w-[min(92vw,44rem)] rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                        <p class="px-3 pb-1.5 pt-1 text-xs font-bold uppercase tracking-wide text-slate-400">My sections</p>
                        <div class="grid gap-1 sm:grid-cols-2">
                            @foreach ($links as $link)
                                <a href="{{ route($link['route']) }}"
                                   class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] font-semibold transition {{ request()->routeIs($link['pattern']) ? 'bg-brand-50 text-brand-700' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                                    <x-icon :name="$link['icon']" class="h-5 w-5 shrink-0" />
                                    <span>{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-6xl px-4 pb-24 pt-6 lg:pb-10">
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

        @if ($errors->count() > 1)
            <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p class="font-medium">Please check the form below.</p>
                <ul class="mt-1 list-inside list-disc text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white lg:hidden">
        <div class="mx-auto grid max-w-md grid-cols-5">
            @foreach ($mobileLinks as $link)
                <a href="{{ route($link['route']) }}"
                   class="flex flex-col items-center gap-1 px-1 py-2.5 text-[12px] font-semibold {{ request()->routeIs($link['pattern']) ? 'text-brand-700' : 'text-slate-600' }}">
                    <x-icon :name="$link['icon']" class="h-5 w-5" />
                    <span>{{ $link['label'] }}</span>
                </a>
            @endforeach
        </div>
    </nav>

    @stack('scripts')
</body>
</html>
