<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#fafafa', 100: '#f4f4f5', 200: '#e4e4e7', 300: '#d4d4d8',
                            400: '#a1a1aa', 500: '#71717a', 600: '#3f3f46', 700: '#27272a',
                            800: '#1f1f23', 900: '#18181b', 950: '#09090b',
                        },
                    },
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                },
            },
        };
    </script>
    <style>body { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="h-full bg-slate-100 antialiased">
    <div class="flex min-h-full">
        <aside class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-brand-900 p-12 text-white lg:flex">

            <div class="relative">
                <a href="{{ route('home') }}" class="flex items-center gap-3" aria-label="Go to the public landing page">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-400 text-lg font-bold text-brand-950">C</span>
                    <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
                </a>

                <h1 class="mt-16 max-w-md text-3xl font-semibold leading-tight">
                    Savings, merry-go-round, table banking and lending — in one ledger.
                </h1>
                <p class="mt-4 max-w-md text-sm leading-relaxed text-brand-100/80">
                    Every shilling is posted twice, every member sees only their own records,
                    and officials act within the limits their group configures.
                </p>
            </div>

            <dl class="relative grid grid-cols-2 gap-6 text-sm">
                <div>
                    <dt class="text-brand-200/70">Books</dt>
                    <dd class="mt-1 font-medium">Double-entry by default</dd>
                </div>
                <div>
                    <dt class="text-brand-200/70">Access</dt>
                    <dd class="mt-1 font-medium">Role and group scoped</dd>
                </div>
                <div>
                    <dt class="text-brand-200/70">Money</dt>
                    <dd class="mt-1 font-medium">Integer minor units</dd>
                </div>
                <div>
                    <dt class="text-brand-200/70">Audit</dt>
                    <dd class="mt-1 font-medium">Append-only trail</dd>
                </div>
            </dl>
        </aside>

        <main class="flex w-full flex-col justify-center px-4 py-10 lg:w-1/2 lg:px-16">
            <div class="mx-auto w-full max-w-sm">
                <a href="{{ route('home') }}" class="mb-8 flex items-center gap-3 lg:hidden" aria-label="Go to the public landing page">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">C</span>
                    <span class="font-semibold text-slate-900">{{ config('app.name') }}</span>
                </a>

                @if (session('status'))
                    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        {{ session('error') }}
                    </div>
                @endif

                @yield('content')

                <p class="mt-8 text-center text-xs text-slate-400">
                    &copy; {{ now()->year }} {{ config('app.name') }}. Group-managed funds only.
                </p>
            </div>
        </main>
    </div>

    @stack('scripts')
</body>
</html>
