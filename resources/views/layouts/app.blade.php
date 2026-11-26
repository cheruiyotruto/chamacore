@php
    $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
    $group = $currentGroup ?? $ctx->group();
    $membership = $currentMembership ?? $ctx->membership();
    $user = auth()->user();
    $can = fn (string $permission) => $ctx->hasPermission($permission);
    $memberships = $user?->memberships()->with('group')->get() ?? collect();

    $sections = \App\Support\StaffNavigation::sections();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $group?->name ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    @include('partials.appearance', ['theme' => \App\Support\Appearance::for($group)])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
    @stack('head')
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <div x-data="{ sidebar: false }" class="min-h-screen lg:flex">
        <div x-cloak x-show="sidebar" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="sidebar = false"></div>

        <aside
            x-cloak
            :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-200 bg-white transition-transform lg:static lg:translate-x-0"
        >
            <div class="flex h-16 items-center gap-3 border-b border-slate-200 px-5">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3" aria-label="Go to the public landing page">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">C</span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ $group?->name ?? config('app.name') }}</p>
                        <p class="text-xs text-slate-500">{{ $membership?->role?->name ?? 'Staff' }}</p>
                    </div>
                </a>
            </div>

            <nav class="flex-1 space-y-4 overflow-y-auto px-3 py-5">
                @foreach ($sections as $section)
                    @php
                        $items = collect($section['items'])->filter(
                            fn ($item) => $item['permission'] === null || $can($item['permission'])
                        );
                    @endphp

                    @if ($items->isNotEmpty())
                        @php
                            $sectionActive = $items->contains(fn ($item) => request()->routeIs($item['pattern']));
                        @endphp

                        <div @if ($section['label']) x-data="{ open: {{ $sectionActive ? 'true' : 'false' }} }" @endif>
                            @if ($section['label'])
                                <button type="button"
                                        @click="open = !open"
                                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-[13px] font-bold uppercase tracking-wide text-slate-700 transition hover:bg-slate-100 hover:text-slate-900">
                                    <span>{{ $section['label'] }}</span>
                                    <x-icon name="chevron-down" class="h-4 w-4 text-slate-500 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
                                </button>
                            @endif
                            <div class="mt-1 space-y-1" @if ($section['label']) x-cloak x-show="open" x-transition.opacity @endif>
                                @foreach ($items as $item)
                                    <a href="{{ route($item['route']) }}"
                                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $section['label'] === null ? 'text-[15px] font-semibold' : 'text-sm font-medium' }} {{ request()->routeIs($item['pattern']) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                        <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

                @unless ($ctx->isMember())
                    <div class="border-t border-slate-200 pt-4">
                        <a href="{{ route('portal.dashboard') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                            <x-icon name="arrow-right" class="h-5 w-5" />
                            <span>My member portal</span>
                        </a>
                    </div>
                @endunless
            </nav>

            <div class="border-t border-slate-200 p-3">
                <div class="rounded-lg bg-slate-50 px-3 py-2.5">
                    <p class="text-xs font-medium text-slate-700">{{ $user?->name }}</p>
                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ $user?->email }}</p>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="sidebar = true" aria-label="Open navigation">
                        <x-icon name="menu" />
                    </button>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">@yield('title', 'Dashboard')</p>
                        <p class="hidden text-xs text-slate-500 sm:block">{{ now()->format('l, d F Y') }}</p>
                    </div>
                </div>

                @if ($ctx->isStaff() && $can('members.view'))
                    <form method="GET" action="{{ route('members.lookup') }}" role="search" class="hidden min-w-0 flex-1 justify-center sm:flex lg:px-6">
                        <label for="global-member-search" class="sr-only">Search all members</label>
                        <div class="relative w-full max-w-xs md:max-w-md">
                            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input id="global-member-search"
                                   name="q"
                                   type="search"
                                   value="{{ request('q') }}"
                                   placeholder="Search name, number, ID or anything else"
                                   autocomplete="off"
                                   class="w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-9 pr-3 text-sm text-slate-800 placeholder:text-slate-400 hover:border-slate-300 focus:border-brand-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100" />
                        </div>
                    </form>
                @endif

                <div class="flex items-center gap-1.5">
                    @if ($unreadNotifications ?? false)
                        <a href="{{ route('notifications.index') }}" class="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Notifications">
                            <x-icon name="bell" />
                            <span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white">{{ $unreadNotifications }}</span>
                        </a>
                    @endif

                    @if ($memberships->count() > 1)
                        <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                            <button type="button" @click="open = !open" class="hidden items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 sm:flex">
                                <span class="h-2 w-2 rounded-full bg-brand-500"></span>
                                {{ $group?->name }}
                                <x-icon name="menu" class="h-3.5 w-3.5" />
                            </button>
                            <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                                <p class="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Switch group</p>
                                @foreach ($memberships as $option)
                                    <form method="POST" action="{{ route('profile.switch-group', $option->group) }}">
                                        @csrf
                                        <button type="submit" class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-left text-sm {{ $option->group_id === $group?->id ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}">
                                            <span class="truncate">{{ $option->group?->name }}</span>
                                            <span class="text-xs text-slate-400">{{ $option->role?->name }}</span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                        <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-full p-0.5 hover:bg-slate-100">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $user?->initials() }}</span>
                        </button>
                        <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                            <div class="border-b border-slate-100 px-2 pb-2">
                                <p class="truncate text-sm font-medium text-slate-900">{{ $user?->name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $user?->email }}</p>
                            </div>
                            <a href="{{ route('profile.edit') }}" class="mt-1 block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Profile &amp; security</a>
                            <a href="{{ route('mfa.setup') }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Two-factor authentication</a>
                            @if ($user?->isSuperAdmin())
                                <a href="{{ route('platform.dashboard') }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Platform administration</a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-100 pt-1">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-2 py-1.5 text-left text-sm text-rose-600 hover:bg-rose-50">Sign out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 px-4 py-6 lg:px-8">
                <div class="mx-auto w-full max-w-7xl space-y-5">
                    @if ($group && (int) session(\App\Support\GroupResolver::FULL_CONTROL_KEY) === $group->id)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <p class="font-medium">Full platform control is active in {{ $group->name }}.</p>
                            <form method="POST" action="{{ route('platform.groups.leave') }}">
                                @csrf
                                <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Return to platform</button>
                            </form>
                        </div>
                    @endif

                    @if (session('status'))
                        <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" />
                            <p>{{ session('status') }}</p>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            <x-icon name="x" class="mt-0.5 h-4 w-4 shrink-0" />
                            <p>{{ session('error') }}</p>
                        </div>
                    @endif

                    @if ($errors->count() > 1)
                        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            <p class="font-medium">Please check the form below.</p>
                            <ul class="mt-1 list-inside list-disc text-xs">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
