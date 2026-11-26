@props([
    'name' => 'signature',
    'label' => 'Electronic signature',
    'help' => 'Draw your usual signature in the box using a mouse, stylus or finger.',
    'required' => false,
])

@php($required = (bool) $required)

<div class="rounded-lg border border-slate-200 bg-white p-3" data-signature-pad>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <p class="text-sm font-medium text-slate-800">
                {{ $label }}@if ($required)<span class="text-rose-500"> *</span>@endif
            </p>
            <p class="mt-0.5 text-xs text-slate-500">{{ $help }}</p>
        </div>
        <button type="button" data-signature-clear class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
            Clear signature
        </button>
    </div>

    <canvas width="800" height="220" style="touch-action: none;"
            data-signature-canvas
            class="mt-3 w-full cursor-crosshair rounded-lg border border-dashed border-slate-300 bg-white"></canvas>
    <input type="hidden" name="{{ $name }}" value="{{ old($name) }}" data-signature-input>

    @error($name)
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>

@pushOnce('scripts')
<script>
    (function () {
        function paintBackground(context, canvas) {
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
        }

        function initialise(root) {
            var canvas = root.querySelector('[data-signature-canvas]');
            var input = root.querySelector('[data-signature-input]');
            var clear = root.querySelector('[data-signature-clear]');

            if (! canvas || ! input || canvas.dataset.signatureReady === '1') {
                return;
            }

            canvas.dataset.signatureReady = '1';

            var context = canvas.getContext('2d');
            var drawing = false;
            var dirty = false;
            var last = null;

            context.strokeStyle = '#0f172a';
            context.lineWidth = 2.5;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            paintBackground(context, canvas);

            function position(event) {
                var rect = canvas.getBoundingClientRect();

                return {
                    x: (event.clientX - rect.left) * canvas.width / rect.width,
                    y: (event.clientY - rect.top) * canvas.height / rect.height
                };
            }

            canvas.addEventListener('pointerdown', function (event) {
                event.preventDefault();
                drawing = true;
                dirty = true;
                last = position(event);
                canvas.setPointerCapture(event.pointerId);
            });

            canvas.addEventListener('pointermove', function (event) {
                if (! drawing) {
                    return;
                }

                var point = position(event);
                context.beginPath();
                context.moveTo(last.x, last.y);
                context.lineTo(point.x, point.y);
                context.stroke();
                last = point;
            });

            ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (name) {
                canvas.addEventListener(name, function () {
                    drawing = false;
                });
            });

            if (clear) {
                clear.addEventListener('click', function () {
                    paintBackground(context, canvas);
                    input.value = '';
                    dirty = false;
                });
            }

            var form = canvas.closest('form');

            if (form) {
                form.addEventListener('submit', function () {
                    if (dirty) {
                        input.value = canvas.toDataURL('image/png');
                    }
                });
            }
        }

        function boot() {
            document.querySelectorAll('[data-signature-pad]').forEach(initialise);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
</script>
@endpushOnce
