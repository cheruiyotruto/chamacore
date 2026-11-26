@php
    $theme = $theme ?? \App\Support\Appearance::for($group ?? null);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Join') · {{ $group?->name ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    @include('partials.appearance', ['theme' => $theme])
    @stack('head')
</head>
<body class="min-h-full text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-4xl items-center gap-3 px-4 py-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-sm font-bold text-white">
                {{ mb_substr($group?->name ?? 'C', 0, 1) }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">{{ $group?->name ?? config('app.name') }}</p>
                <p class="text-xs text-slate-500">Membership application</p>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-4xl px-4 py-8">
        @if (session('status'))
            <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                {{ session('error') }}
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

    <footer class="mx-auto max-w-4xl px-4 pb-8 text-center text-xs text-slate-400">
        &copy; {{ now()->year }} {{ $group?->name ?? config('app.name') }}. Your information is stored securely in the group EDMS.
    </footer>

    @stack('scripts')
</body>
</html>
