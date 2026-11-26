@php
    $theme = $theme ?? \App\Support\Appearance::for($group ?? null);
@endphp

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    brand: @json($theme['accent']),
                },
                fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
            },
        },
    };
</script>
<style>
    :root {
        --theme-border: {{ $theme['border_color'] }};
        --theme-page-bg: {{ $theme['background_color'] }};
        --neutral-black: #6b6b6b;
        --neutral-black-hover: #565656;
    }

    html, body {
        background-color: var(--theme-page-bg) !important;
    }

    body { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
    [x-cloak] { display: none !important; }

    /*
     * Separation lines are deliberately stronger than Tailwind's very pale
     * defaults so panel, table and row boundaries stay visible on every page.
     * This applies to the default slate theme as well as the group's chosen
     * border colour. Semantic alert colours are left alone. Near-black button
     * surfaces are converted to neutral gray below.
     */
    .border-slate-100,
    .border-slate-200,
    .border-slate-300,
    .divide-slate-100 > :not([hidden]) ~ :not([hidden]),
    .divide-slate-200 > :not([hidden]) ~ :not([hidden]),
    .divide-slate-300 > :not([hidden]) ~ :not([hidden]) {
        border-color: var(--theme-border) !important;
    }

    /* Near-black surfaces become neutral gray without harsh square corners. */
    .bg-slate-900:not(.bg-slate-900\/50):not(.bg-slate-900\/5) {
        background-color: var(--neutral-black) !important;
    }

    .hover\:bg-slate-800:hover {
        background-color: var(--neutral-black-hover) !important;
    }

    .border-slate-800 {
        border-color: var(--neutral-black-hover) !important;
    }

    .bg-slate-900.rounded-lg {
        border-radius: 9999px !important;
    }
</style>
