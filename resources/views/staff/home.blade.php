<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Workspace · {{ $group->name }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    @include('partials.appearance', ['theme' => \App\Support\Appearance::for($group)])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <header class="px-6 pt-10 text-center sm:pt-14">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">{{ $group->name }}</p>
        <p class="mt-1.5 text-sm text-slate-400">{{ now()->format('l, d F Y') }}</p>
    </header>

    <div x-data="{ open: false }" class="fixed bottom-5 left-5 z-50">
        <div x-cloak
             x-show="open"
             x-transition.opacity.scale.origin.bottom.left
             @click.outside="open = false"
             class="mb-3 w-[min(92vw,28rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">

            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400">Staff workspace</p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-900">Where to enter?</p>
                    <p class="truncate text-xs text-slate-500">{{ $group->name }}</p>
                </div>
                <button type="button" @click="open = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none" aria-label="Close menu">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>

            <nav class="max-h-[65vh] overflow-y-auto px-2 py-3">
                @foreach ($sections as $section)
                    @if ($section['label'])
                        <p class="px-2 pt-3 text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ $section['label'] }}</p>
                    @endif

                    <div class="mt-1 space-y-0.5">
                        @foreach ($section['items'] as $item)
                            <a href="{{ route($item['route']) }}"
                               @click="open = false"
                               class="flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900 focus:outline-none">
                                <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0 text-slate-400" />
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach

                <div class="mt-3 border-t border-slate-100 pt-3">
                    <a href="{{ route('portal.dashboard') }}"
                       @click="open = false"
                       class="flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900 focus:outline-none">
                        <x-icon name="arrow-right" class="h-4 w-4 shrink-0 text-slate-400" />
                        <span>Member portal</span>
                    </a>
                </div>
            </nav>

            <div class="flex items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-4 py-2.5 text-xs">
                <a href="{{ route('profile.edit') }}" class="font-medium text-slate-600 hover:text-slate-900 focus:outline-none">Profile &amp; security</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="font-medium text-rose-600 hover:text-rose-700 focus:outline-none">Sign out</button>
                </form>
            </div>
        </div>

        <button type="button"
                @click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-label="Open workspace menu"
                class="flex items-center gap-2 rounded-full border border-slate-800 bg-slate-900 px-4 py-3 text-sm font-semibold text-white shadow-lg hover:bg-slate-800 focus:outline-none">
            <x-icon name="home" class="h-5 w-5" />
            <span>Home</span>
            <x-icon name="chevron-down" class="h-4 w-4 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
        </button>
    </div>
</body>
</html>
