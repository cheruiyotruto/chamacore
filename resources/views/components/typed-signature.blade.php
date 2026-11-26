@props([
    'name' => 'typed_signature',
    'consentName' => 'typed_signature_consent',
    'label' => 'Typed signature',
    'help' => 'Type your full legal name and confirm that you intend it as your electronic signature.',
    'required' => false,
])

@php($required = (bool) $required)
@php($nameId = 'field-'.str_replace(['[', ']', '.'], '-', $name))
@php($consentId = 'field-'.str_replace(['[', ']', '.'], '-', $consentName))

<div class="rounded-lg border border-slate-200 bg-white p-3">
    <p class="text-sm font-medium text-slate-800">
        {{ $label }}@if ($required)<span class="text-rose-500"> *</span>@endif
    </p>
    <p class="mt-0.5 text-xs text-slate-500">{{ $help }}</p>

    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        <div>
            <label for="{{ $nameId }}" class="block text-sm font-medium text-slate-700">Full legal name</label>
            <input id="{{ $nameId }}"
                   type="text"
                   name="{{ $name }}"
                   value="{{ old($name) }}"
                   maxlength="150"
                   autocomplete="name"
                   placeholder="Type your full name"
                   @if ($required) required @endif
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
            @error($name)
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <label for="{{ $consentId }}" class="flex items-start gap-2 self-end rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <input id="{{ $consentId }}"
                   type="checkbox"
                   name="{{ $consentName }}"
                   value="1"
                   @checked(old($consentName))
                   class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>I agree that typing my name above is my electronic signature and has the same effect as my handwritten signature.</span>
        </label>
    </div>

    @error($consentName)
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
