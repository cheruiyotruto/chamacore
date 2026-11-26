@props(['idPrefix' => null])

@php
    $idPrefix = $idPrefix ?: 'cheque-'.Illuminate\Support\Str::random(4);
@endphp

<div data-cheque-fields style="display: none;" class="sm:col-span-2 grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
    <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">Cheque details</p>

    <div class="grid gap-3 sm:grid-cols-2">
        <x-input :id="$idPrefix.'-number'" name="cheque_number" label="Cheque number" placeholder="e.g. 000123" help="Required for cheque deposits" />
        <x-input :id="$idPrefix.'-bank'" name="bank_name" label="Bank" placeholder="e.g. Equity Bank" />
        <x-input :id="$idPrefix.'-branch'" name="branch" label="Branch" placeholder="Optional" />
        <x-input :id="$idPrefix.'-drawer'" name="drawer_name" label="Drawer / account name" placeholder="Optional" />
        <x-input :id="$idPrefix.'-date'" name="cheque_date" type="date" label="Cheque date" />
        <x-input :id="$idPrefix.'-payee'" name="payee_name" label="Payee" placeholder="Optional" />
    </div>

    <p class="text-xs text-amber-800">
        The deposit is held pending clearance. Nothing is posted to the ledger until the cheque is marked cleared.
    </p>
</div>

<script>
    (function () {
        const script = document.currentScript;
        const form = script && script.closest('form');
        const box = form && form.querySelector('[data-cheque-fields]');
        const select = form && form.querySelector('select[name="method"]');

        if (!box || !select) {
            return;
        }

        const toggle = function () {
            box.style.display = select.value === 'cheque' ? 'grid' : 'none';
        };

        select.addEventListener('change', toggle);
        toggle();
    })();
</script>
