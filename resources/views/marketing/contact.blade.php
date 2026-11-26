@extends('layouts.marketing')

@section('title', 'Request access')
@section('description', 'Tell Chama Core about your chama, SACCO, welfare group or organisation and get help choosing the right setup.')

@section('content')
    @php
        $selectedPlan = old('plan', request('plan'));
    @endphp

    <section class="relative overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-ocean-700 pb-12 pt-16 sm:pt-20">
        <div class="relative mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Get started</p>
            <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-white sm:text-5xl">Let's set up Chama Core around your group.</h1>
            <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-300">
                Share a few details about how your organisation works. We will review your request, recommend a plan and help you prepare the workspace before members are invited.
            </p>
        </div>
    </section>

    <section class="bg-slate-50 pb-20 sm:pb-24">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[1.35fr_0.65fr] lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-card sm:p-9">
                <div>
                    <h2 class="text-xl font-extrabold text-ink-900">Access request</h2>
                    <p class="mt-1 text-sm text-slate-500">Fields marked with an asterisk are required.</p>
                </div>

                <form method="POST" action="{{ route('marketing.contact.store') }}" class="mt-7 space-y-6">
                    @csrf

                    <div class="sr-only" aria-hidden="true">
                        <label for="website">Leave this field empty</label>
                        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="block text-sm font-bold text-ink-900">Your name <span class="text-coral-600">*</span></label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                                   class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 placeholder:text-slate-400 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('name') border-coral-500 @enderror"
                                   placeholder="e.g. Amina Mwangi">
                            @error('name') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="organisation" class="block text-sm font-bold text-ink-900">Organisation / group <span class="text-coral-600">*</span></label>
                            <input id="organisation" name="organisation" type="text" value="{{ old('organisation') }}" required autocomplete="organization"
                                   class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 placeholder:text-slate-400 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('organisation') border-coral-500 @enderror"
                                   placeholder="e.g. Umoja Women's Group">
                            @error('organisation') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="email" class="block text-sm font-bold text-ink-900">Email address <span class="text-coral-600">*</span></label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                                   class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 placeholder:text-slate-400 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('email') border-coral-500 @enderror"
                                   placeholder="you@example.com">
                            @error('email') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-bold text-ink-900">Phone number</label>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel"
                                   class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 placeholder:text-slate-400 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('phone') border-coral-500 @enderror"
                                   placeholder="e.g. 0712 345 678">
                            @error('phone') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="group_type" class="block text-sm font-bold text-ink-900">Type of organisation</label>
                            <select id="group_type" name="group_type"
                                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('group_type') border-coral-500 @enderror">
                                <option value="">Select one</option>
                                @foreach (['Chama / investment club', 'SACCO / cooperative', 'Welfare or community group', 'Employer / staff group', 'Umbrella organisation or network', 'Other'] as $type)
                                    <option value="{{ $type }}" @selected(old('group_type') === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                            @error('group_type') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="expected_members" class="block text-sm font-bold text-ink-900">Expected members</label>
                            <input id="expected_members" name="expected_members" type="number" min="1" max="1000000" value="{{ old('expected_members') }}"
                                   class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 placeholder:text-slate-400 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('expected_members') border-coral-500 @enderror"
                                   placeholder="e.g. 120">
                            @error('expected_members') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="plan" class="block text-sm font-bold text-ink-900">Plan of interest</label>
                        <select id="plan" name="plan"
                                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('plan') border-coral-500 @enderror">
                            <option value="">Help me choose</option>
                            @foreach ($plans as $plan)
                                <option value="{{ $plan['slug'] }}" @selected($selectedPlan === $plan['slug'])>
                                    {{ $plan['name'] }} · {{ $plan['price_label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('plan') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="message" class="block text-sm font-bold text-ink-900">What would you like us to know?</label>
                        <textarea id="message" name="message" rows="5" maxlength="2000"
                                  class="mt-2 w-full resize-y rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-ink-900 placeholder:text-slate-400 focus:border-ocean-500 focus:outline-none focus:ring-2 focus:ring-ocean-500/20 @error('message') border-coral-500 @enderror"
                                  placeholder="Tell us about your current process, the modules you need or any questions you have.">{{ old('message') }}</textarea>
                        @error('message') <p class="mt-1.5 text-xs font-medium text-coral-600">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-ocean-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-ocean-900/10 transition hover:bg-ocean-700 focus:outline-none focus:ring-2 focus:ring-ocean-500 focus:ring-offset-2 sm:w-auto">
                        Send access request
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </button>
                </form>
            </div>

            <div class="space-y-6">
                <div class="rounded-3xl bg-ink-900 p-7 text-white shadow-lift sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">What happens next</p>
                    <div class="mt-6 space-y-6">
                        @foreach ([
                            ['01', 'We review your request', 'We look at your group type, size and the workflows you described.'],
                            ['02', 'We recommend a setup', 'You get guidance on the plan, roles, products and payment options that fit.'],
                            ['03', 'You invite your members', 'Once the workspace is ready, authorised officials invite members securely.'],
                        ] as $step)
                            <div class="flex gap-4">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-xs font-bold text-jade-300">{{ $step[0] }}</span>
                                <div>
                                    <p class="text-sm font-bold">{{ $step[1] }}</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-300">{{ $step[2] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-3xl border border-ocean-200 bg-ocean-50 p-7">
                    <x-icon name="shield" class="h-6 w-6 text-ocean-700" />
                    <h2 class="mt-4 text-base font-bold text-ink-900">Already have an account?</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Members and officials should sign in instead of submitting a request.</p>
                    <a href="{{ route('login') }}" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-ocean-700 hover:text-ocean-800">
                        Go to sign in
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>

                <div class="rounded-3xl border border-jade-200 bg-jade-50 p-7">
                    <x-icon name="receipt" class="h-6 w-6 text-jade-700" />
                    <h2 class="mt-4 text-base font-bold text-ink-900">Want to compare first?</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">See member limits and module bundles before you choose a plan.</p>
                    <a href="{{ route('marketing.pricing') }}" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-jade-700 hover:text-jade-800">
                        View pricing
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
