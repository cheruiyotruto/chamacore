<section class="bg-gradient-to-br from-ocean-700 via-ink-800 to-jade-700 py-16 sm:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-white/5 px-6 py-10 shadow-lift sm:px-12 sm:py-14">
            <div class="relative flex flex-col items-start justify-between gap-8 lg:flex-row lg:items-center">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Ready when your group is</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                        Give your group one clear place for money, members and decisions.
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-200">
                        Share a few details about your organisation. We will help you map your current process, choose a plan and set up a workspace your members can trust.
                    </p>
                </div>

                <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                    <a href="{{ route('marketing.contact') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-jade-500 px-6 py-3.5 text-sm font-bold text-ink-950 shadow-lg shadow-jade-900/20 transition hover:bg-jade-400 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-ink-900">
                        Request access
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                    <a href="{{ route('marketing.pricing') }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 px-6 py-3.5 text-sm font-bold text-white transition hover:bg-white/10">
                        Compare plans
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
