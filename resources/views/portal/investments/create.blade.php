@extends('layouts.portal')

@section('title', 'Place an investment')

@section('content')
    @php
        $productOptions = $products->mapWithKeys(fn ($product) => [$product->id => $product->name.' ('.$product->code.')'])->all();
        $productMeta = $products->mapWithKeys(fn ($product) => [$product->id => [
            'name' => $product->name,
            'code' => $product->code,
            'rate_bps' => (int) $product->interest_rate_bps,
            'period' => $product->periodLabel(),
            'method' => $product->methodLabel(),
            'duration' => (int) $product->duration_months,
            'min' => (int) $product->min_amount,
            'max' => $product->max_amount !== null ? (int) $product->max_amount : null,
            'early' => (bool) $product->early_withdrawal_allowed,
            'penalty_bps' => (int) $product->early_withdrawal_penalty_bps,
            'terms' => $product->terms,
        ]])->all();
        $initialProduct = (string) old('investment_product_id', request('product', ''));
        $today = now()->toDateString();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">Place an investment</h1>
            <p class="mt-1 text-sm text-slate-500">Choose a product and amount. The money moves from your wallet into the investment.</p>
        </div>
        <a href="{{ route('portal.investments.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> My investments
        </a>
    </div>

    @if ($products->isEmpty())
        <x-empty title="No investment products available" message="The group has not published an investment product yet. Please contact the office." />
    @else
        <form method="POST" action="{{ route('portal.investments.store') }}" class="grid gap-5 xl:grid-cols-3"
              x-data="portalInvestment({
                  productMeta: @js($productMeta),
                  quoteUrl: '{{ route('portal.investments.quote') }}',
                  initialProduct: @js($initialProduct),
              })">
            @csrf

            <div class="space-y-5 xl:col-span-2">
                <x-card title="Investment details" subtitle="All amounts are in Kenyan shillings.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select name="investment_product_id" label="Investment product" :options="$productOptions" placeholder="Select a product" required x-model="productId" />
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount to invest" required x-model="amount" />
                        <x-input name="start_date" type="date" label="Start date" :value="$today" x-model="startDate" @change="loadQuote()" />
                        <div class="flex items-end">
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                Wallet available:
                                <span class="font-semibold text-slate-800">@money($wallet?->available_balance ?? 0)</span>
                            </p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <x-textarea name="notes" label="Notes" rows="3" placeholder="Optional reference or instructions for the office" />
                    </div>

                    <label class="mt-4 flex items-start gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="terms" value="1" required class="mt-0.5 rounded border-slate-300 text-brand-600">
                        <span>I have read the product terms and authorise the group to move this amount from my wallet into the investment.</span>
                    </label>
                    @error('terms')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </x-card>

                <x-card title="Interest schedule preview" subtitle="This is the interest that will accrue over the term." :padding="false">
                    <div x-cloak x-show="quote?.schedule?.length" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">#</th>
                                    <th class="px-4 py-2.5">Period</th>
                                    <th class="px-4 py-2.5 text-right">Months</th>
                                    <th class="px-4 py-2.5 text-right">Opening</th>
                                    <th class="px-4 py-2.5 text-right">Interest</th>
                                    <th class="px-4 py-2.5 text-right">Closing</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="line in (quote?.schedule ?? [])" :key="line.period_no">
                                    <tr>
                                        <td class="px-4 py-2.5 text-slate-600" x-text="line.period_no"></td>
                                        <td class="px-4 py-2.5 text-slate-600">
                                            <span x-text="line.period_start"></span> → <span x-text="line.period_end"></span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-slate-600" x-text="line.months"></td>
                                        <td class="px-4 py-2.5 text-right text-slate-600" x-text="money(line.opening_balance)"></td>
                                        <td class="px-4 py-2.5 text-right text-emerald-700" x-text="money(line.interest_amount)"></td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900" x-text="money(line.closing_balance)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <p x-show="!quote && !error" class="p-4 text-sm text-slate-500">
                        Choose a product and enter an amount to preview the interest schedule.
                    </p>
                    <p x-cloak x-show="error" x-text="error" class="m-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700"></p>
                </x-card>
            </div>

            <div class="space-y-5">
                <x-card title="Product terms" subtitle="The selected product's rules.">
                    <p x-show="!product" class="text-sm text-slate-500">Select a product to see its terms.</p>

                    <div x-cloak x-show="product" class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Annual interest</span>
                            <span class="font-semibold text-slate-900" x-text="product ? percent(product.rate_bps) + '% p.a.' : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Interest period</span>
                            <span class="text-right font-medium text-slate-900" x-text="product?.period"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Method</span>
                            <span class="font-medium text-slate-900" x-text="product?.method"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Term</span>
                            <span class="font-medium text-slate-900" x-text="product ? product.duration + ' month(s)' : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Amount range</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? money(product.min) + (product.max ? ' – ' + money(product.max) : ' and above') : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Early withdrawal</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? (product.early ? 'Allowed · ' + percent(product.penalty_bps) + '% penalty' : 'Locked until maturity') : ''"></span>
                        </div>
                        <p x-cloak x-show="product?.terms" class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500" x-text="product?.terms"></p>
                    </div>
                </x-card>

                <x-card title="Returns">
                    <button type="button" @click="loadQuote()" :disabled="loading || !productId || !amount"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">
                        <x-icon name="chart" class="h-4 w-4" />
                        <span x-text="loading ? 'Calculating…' : 'Preview returns'"></span>
                    </button>

                    <dl x-cloak x-show="quote" class="mt-4 space-y-2.5 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Principal</dt>
                            <dd class="font-semibold text-slate-900" x-text="money(amountMinor())"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Expected interest</dt>
                            <dd class="font-medium text-emerald-700" x-text="money(quote?.expected_interest)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Interest periods</dt>
                            <dd class="font-medium text-slate-800" x-text="quote?.period_count"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Maturity date</dt>
                            <dd class="font-medium text-slate-800" x-text="quote?.maturity_date"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="font-medium text-slate-700">Value at maturity</dt>
                            <dd class="font-semibold text-slate-900" x-text="money(quote?.expected_total)"></dd>
                        </div>
                    </dl>

                    <p x-show="!quote && !error" class="mt-3 text-xs text-slate-500">Enter an amount to see the expected interest and maturity value.</p>
                    <p x-cloak x-show="error" x-text="error" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700"></p>
                </x-card>

                <x-card title="Confirm">
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="check" class="h-4 w-4" /> Invest from wallet
                    </button>
                    <a href="{{ route('portal.investments.index') }}" class="mt-2 block text-center text-sm font-medium text-slate-500 hover:text-slate-700">Cancel</a>
                </x-card>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        function portalInvestment({ productMeta, quoteUrl, initialProduct }) {
            return {
                productMeta,
                quoteUrl,
                productId: initialProduct || '',
                amount: '',
                startDate: @js($today),
                quote: null,
                error: null,
                loading: false,

                init() {
                    this.$watch('productId', () => this.loadQuote());
                    this.$watch('amount', () => this.loadQuote());

                    if (this.productId) {
                        this.loadQuote();
                    }
                },

                get product() {
                    return this.productMeta[this.productId] ?? null;
                },

                percent(bps) {
                    return (Number(bps || 0) / 100).toFixed(2);
                },

                amountMinor() {
                    return Math.round(Number(this.amount || 0) * 100);
                },

                money(minor) {
                    const value = Number(minor || 0) / 100;

                    return 'KES ' + value.toLocaleString('en-KE', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },

                async loadQuote() {
                    if (! this.productId || ! this.amount || Number(this.amount) <= 0) {
                        this.quote = null;

                        return;
                    }

                    this.error = null;
                    this.loading = true;

                    try {
                        const response = await fetch(this.quoteUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                investment_product_id: this.productId,
                                amount: this.amount,
                                start_date: this.startDate,
                            }),
                        });

                        const data = await response.json().catch(() => ({}));

                        if (! response.ok) {
                            this.error = data.message || 'The quotation could not be calculated.';
                            this.quote = null;

                            return;
                        }

                        this.quote = data;
                    } catch (e) {
                        this.error = 'Unable to reach the quotation service.';
                        this.quote = null;
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
    </script>
@endpush
