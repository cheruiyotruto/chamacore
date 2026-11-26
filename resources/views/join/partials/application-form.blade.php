@php
    $mode = $requirements->identityMode();
    $showNextOfKin = $requirements->fieldEnabled('next_of_kin_name')
        || $requirements->fieldEnabled('next_of_kin_relationship')
        || $requirements->fieldEnabled('next_of_kin_phone');
    $mediaHelp = match ($mode) {
        'photo' => 'A passport photograph is required for this group.',
        'video' => 'A short face video is required for this group.',
        'both' => 'Both a passport photograph and a short face video are required for this group.',
        'either' => 'Upload at least one: a passport photograph or a short face video.',
        default => 'Identity media is optional for this group, but helps officials verify you faster.',
    };
    $documents = $requirements->enabledDocuments();
    $documentsRequired = collect($documents)->where('required', true)->count();
    $drawnSignatureEnabled = $requirements->documentEnabled(\App\Support\JoinRequirements::SIGNATURE_KEY);
    $typedSignatureEnabled = $requirements->documentEnabled(\App\Support\JoinRequirements::TYPED_SIGNATURE_KEY);
    $formNote = $formNote ?? 'Your application will be reviewed by a group official before activation.';
    $submitLabel = $submitLabel ?? 'Submit application';
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-5">
    @csrf

    <x-card title="Personal details" subtitle="Please use the names exactly as they appear on your ID.">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-input name="first_name" label="First name" :value="old('first_name')" :required="$requirements->fieldRequired('first_name')" autocomplete="given-name" />
            @if ($requirements->fieldEnabled('middle_name'))
                <x-input name="middle_name" label="Middle name" :value="old('middle_name')" :required="$requirements->fieldRequired('middle_name')" autocomplete="additional-name" />
            @endif
            <x-input name="last_name" label="Last name" :value="old('last_name')" :required="$requirements->fieldRequired('last_name')" autocomplete="family-name" />
            @if ($requirements->fieldEnabled('national_id'))
                <x-input name="national_id" label="National ID / passport number" :value="old('national_id')" :required="$requirements->fieldRequired('national_id')" />
            @endif
            @if ($requirements->fieldEnabled('kra_pin_number'))
                <x-input name="kra_pin_number" label="KRA PIN number" :value="old('kra_pin_number')" :required="$requirements->fieldRequired('kra_pin_number')" placeholder="e.g. A012345678Z" help="Used only if your group asks for it. Leave blank if you do not have one." />
            @endif
            @if ($requirements->fieldEnabled('gender'))
                <x-select name="gender" label="Gender" :options="['female' => 'Female', 'male' => 'Male', 'other' => 'Other']" :value="old('gender')" placeholder="Select gender" :required="$requirements->fieldRequired('gender')" />
            @endif
            @if ($requirements->fieldEnabled('date_of_birth'))
                <x-input name="date_of_birth" type="date" label="Date of birth" :value="old('date_of_birth')" :required="$requirements->fieldRequired('date_of_birth')" max="{{ now()->subYears(18)->toDateString() }}" />
            @endif
        </div>
    </x-card>

    <x-card title="Contact and work">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-input name="email" type="email" label="Email address" :value="old('email', $invitation?->email)" :required="$requirements->fieldRequired('email')" autocomplete="email" help="Your sign-in and approval updates are sent here." />
            @if ($requirements->fieldEnabled('phone'))
                <x-input name="phone" type="tel" label="Phone number" :value="old('phone', $invitation?->phone)" :required="$requirements->fieldRequired('phone')" autocomplete="tel" help="Include the country code, e.g. +2547…" />
            @endif
            @if ($requirements->fieldEnabled('occupation'))
                <x-input name="occupation" label="Occupation" :value="old('occupation')" :required="$requirements->fieldRequired('occupation')" />
            @endif
            @if ($requirements->fieldEnabled('employer'))
                <x-input name="employer" label="Employer / business" :value="old('employer')" :required="$requirements->fieldRequired('employer')" />
            @endif
            @if ($requirements->fieldEnabled('workstation'))
                <x-input name="workstation" label="Workstation / work location" :value="old('workstation')" :required="$requirements->fieldRequired('workstation')" placeholder="e.g. Main campus, Westlands" />
            @endif
        </div>

        @if ($requirements->fieldEnabled('address') || $requirements->fieldEnabled('employer_address'))
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @if ($requirements->fieldEnabled('address'))
                    <x-textarea name="address" label="Home address" :value="old('address')" :required="$requirements->fieldRequired('address')" />
                @endif
                @if ($requirements->fieldEnabled('employer_address'))
                    <x-textarea name="employer_address" label="Work address" :value="old('employer_address')" :required="$requirements->fieldRequired('employer_address')" />
                @endif
            </div>
        @endif

        @if ($requirements->fieldEnabled('membership_category'))
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-select name="membership_category" label="Membership category" :options="['ordinary' => 'Ordinary member', 'associate' => 'Associate member', 'honorary' => 'Honorary member']" :value="old('membership_category', 'ordinary')" :required="$requirements->fieldRequired('membership_category')" />
            </div>
        @endif
    </x-card>

    @if ($showNextOfKin)
        <x-card title="Next of kin" subtitle="Someone the group can contact in an emergency.">
            <div class="grid gap-4 sm:grid-cols-3">
                @if ($requirements->fieldEnabled('next_of_kin_name'))
                    <x-input name="next_of_kin_name" label="Full name" :value="old('next_of_kin_name')" :required="$requirements->fieldRequired('next_of_kin_name')" />
                @endif
                @if ($requirements->fieldEnabled('next_of_kin_relationship'))
                    <x-input name="next_of_kin_relationship" label="Relationship" :value="old('next_of_kin_relationship')" :required="$requirements->fieldRequired('next_of_kin_relationship')" placeholder="e.g. Spouse, sibling" />
                @endif
                @if ($requirements->fieldEnabled('next_of_kin_phone'))
                    <x-input name="next_of_kin_phone" type="tel" label="Phone number" :value="old('next_of_kin_phone')" :required="$requirements->fieldRequired('next_of_kin_phone')" />
                @endif
            </div>
        </x-card>
    @endif

    @if ($documents !== [])
        <x-card title="Documents for approval"
                subtitle="{{ $documentsRequired }} required document(s). Everything is stored privately in the group EDMS.">
            @if ($drawnSignatureEnabled && $typedSignatureEnabled)
                <p class="mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-xs font-medium text-brand-800">
                    This group accepts both a drawn signature and a typed signature. Provide one of them; when a signature is required, either method satisfies it.
                </p>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($documents as $key => $document)
                    @if ($document['signature'] ?? false)
                        <div class="sm:col-span-2">
                            <x-signature-pad :label="$document['label']" :help="$document['help']" :required="$document['required'] && ! $typedSignatureEnabled" />
                        </div>
                    @elseif ($document['typed_signature'] ?? false)
                        <div class="sm:col-span-2">
                            <x-typed-signature :label="$document['label']" :help="$document['help']" :required="$document['required'] && ! $drawnSignatureEnabled" />
                        </div>
                    @else
                        <x-input name="documents[{{ $key }}]" type="file" :label="$document['label']" accept=".pdf,.jpg,.jpeg,.png,.webp" :required="$document['required']" :help="$document['help']" />
                    @endif
                @endforeach
            </div>

            <p class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                Accepted files: PDF, JPG, PNG or WEBP · up to 10 MB each. Your documents are visible only to group officials reviewing your application.
            </p>
        </x-card>
    @endif

    <x-card title="Identity verification" subtitle="Stored in the group's EDMS under your member number.">
        @if ($requirements->anyMediaEnabled())
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($requirements->mediaEnabled('passport_photo'))
                    <x-input name="passport_photo" type="file" label="Passport photo" accept=".jpg,.jpeg,.png,.webp" :required="$mode === 'photo' || $mode === 'both'" help="JPG, PNG or WEBP · up to 5 MB." />
                @endif
                @if ($requirements->mediaEnabled('face_video'))
                    <div>
                        <x-input name="face_video" type="file" label="Face video" accept="video/mp4,video/webm,video/quicktime" :required="$mode === 'video' || $mode === 'both'" help="Record with your camera below, or upload a file · about 20 seconds · up to 20 MB." />

                        <div id="camera-capture" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p class="text-xs font-semibold text-slate-700">Record with your camera</p>
                            <p class="mt-1 text-xs text-slate-500">
                                Use your phone's front camera or a laptop webcam. Face the camera and say your full name and today's date. Phone browsers need HTTPS for camera access.
                            </p>

                            <video id="camera-preview" class="mt-3 hidden w-full rounded-lg bg-slate-900" playsinline autoplay muted></video>
                            <p id="camera-prompt" class="mt-2 hidden rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-800"></p>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <button type="button" id="camera-start" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Start camera</button>
                                <button type="button" id="camera-record" class="hidden rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Record</button>
                                <button type="button" id="camera-stop" class="hidden rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Stop &amp; attach</button>
                                <button type="button" id="camera-retake" class="hidden rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Retake</button>
                                <span id="camera-status" class="text-xs text-slate-500"></span>
                            </div>

                            <p id="camera-error" class="mt-2 hidden text-xs text-rose-600"></p>
                            <p class="mt-2 text-xs text-slate-500">Prefer to upload? Choose a video file above instead.</p>
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                <p class="font-medium text-slate-700">{{ $mediaHelp }}</p>
                @if ($requirements->mediaEnabled('face_video'))
                    <p class="mt-1">
                        While recording, face the camera, slowly turn your head left and right, tilt up and down, then face front again while saying your full name and today's date.
                    </p>
                @endif
                <p class="mt-2">Files are kept private to group officials and are only used for identity verification.</p>
            </div>
        @else
            <p class="text-sm text-slate-500">This group does not require identity media at application time.</p>
        @endif

        <label class="mt-4 flex items-start gap-2 text-sm text-slate-600">
            <input type="checkbox" name="consent" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('consent')) required>
            <span>I confirm the details are correct and consent to my information being stored in the group's EDMS.</span>
        </label>
        @error('consent')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </x-card>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs text-slate-500">{{ $formNote }}</p>
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            {{ $submitLabel }} <x-icon name="arrow-right" class="h-4 w-4" />
        </button>
    </div>
</form>

@push('scripts')
    <script>
        (function () {
            function setupFaceVideoCapture() {
                var input = document.getElementById('face_video');
                var widget = document.getElementById('camera-capture');

                if (!input || !widget) {
                    return;
                }

                if (!navigator.mediaDevices
                    || !navigator.mediaDevices.getUserMedia
                    || !window.MediaRecorder
                    || !window.DataTransfer) {
                    widget.classList.add('hidden');
                    return;
                }

                var start = document.getElementById('camera-start');
                var record = document.getElementById('camera-record');
                var stopButton = document.getElementById('camera-stop');
                var retake = document.getElementById('camera-retake');
                var status = document.getElementById('camera-status');
                var errorBox = document.getElementById('camera-error');
                var prompt = document.getElementById('camera-prompt');
                var preview = document.getElementById('camera-preview');

                var prompts = [
                    "Look straight into the camera and say your full name and today's date.",
                    "Slowly turn your head to your left so we can see your left side.",
                    "Now slowly turn your head to your right so we can see your right side.",
                    "Tilt your head up slightly, then come back to the centre.",
                    "Tilt your head down slightly, then come back to the centre.",
                    "Look straight at the camera again and hold still to finish."
                ];

                var PROMPT_MS = 3000;
                var MIN_SECONDS = 18;
                var MAX_SECONDS = 25;

                var stream = null;
                var recorder = null;
                var chunks = [];
                var startedAt = 0;
                var stoppedAt = 0;
                var timer = null;
                var promptTimer = null;
                var previewUrl = null;
                var mimeType = '';

                function setStatus(text) {
                    status.textContent = text;
                }

                function showError(message) {
                    errorBox.textContent = message;
                    errorBox.classList.remove('hidden');
                }

                function clearError() {
                    errorBox.textContent = '';
                    errorBox.classList.add('hidden');
                }

                function setState(state) {
                    start.classList.toggle('hidden', state !== 'idle');
                    record.classList.toggle('hidden', state !== 'live');
                    stopButton.classList.toggle('hidden', state !== 'recording');
                    retake.classList.toggle('hidden', state === 'idle' || state === 'recording');
                    retake.textContent = state === 'live' ? 'Cancel' : 'Retake';
                    prompt.classList.toggle('hidden', state !== 'recording');
                    preview.classList.toggle('hidden', state === 'idle');
                }

                function stopStream() {
                    if (stream) {
                        stream.getTracks().forEach(function (track) {
                            track.stop();
                        });
                        stream = null;
                    }
                }

                function clearTimers() {
                    if (timer) {
                        window.clearInterval(timer);
                        timer = null;
                    }

                    if (promptTimer) {
                        window.clearInterval(promptTimer);
                        promptTimer = null;
                    }
                }

                function pickMimeType() {
                    var candidates = [
                        'video/webm;codecs=vp9,opus',
                        'video/webm;codecs=vp8,opus',
                        'video/webm;codecs=vp9',
                        'video/webm;codecs=vp8',
                        'video/webm',
                        'video/mp4'
                    ];

                    if (!MediaRecorder.isTypeSupported) {
                        return '';
                    }

                    for (var i = 0; i < candidates.length; i++) {
                        if (MediaRecorder.isTypeSupported(candidates[i])) {
                            return candidates[i];
                        }
                    }

                    return '';
                }

                function openCamera(withAudio) {
                    var constraints = {
                        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
                    };

                    if (withAudio) {
                        constraints.audio = true;
                    }

                    return navigator.mediaDevices.getUserMedia(constraints);
                }

                start.addEventListener('click', function () {
                    clearError();
                    setStatus('Requesting camera…');

                    openCamera(true).catch(function () {
                        return openCamera(false);
                    }).then(function (media) {
                        stream = media;
                        preview.srcObject = stream;
                        preview.muted = true;
                        preview.controls = false;
                        preview.play().catch(function () {});
                        setState('live');
                        setStatus('Camera ready. Follow the prompts while recording.');
                    }).catch(function () {
                        showError('Camera access was blocked or is unavailable. Allow camera access, or upload a video file above.');
                        setStatus('');
                    });
                });

                record.addEventListener('click', function () {
                    if (!stream) {
                        return;
                    }

                    clearError();
                    chunks = [];
                    mimeType = pickMimeType();

                    try {
                        recorder = new MediaRecorder(stream, mimeType ? { mimeType: mimeType } : undefined);
                    } catch (error) {
                        mimeType = '';
                        recorder = new MediaRecorder(stream);
                    }

                    recorder.ondataavailable = function (event) {
                        if (event.data && event.data.size > 0) {
                            chunks.push(event.data);
                        }
                    };

                    recorder.onstop = function () {
                        stopStream();

                        var type = mimeType || 'video/webm';
                        var extension = type.indexOf('mp4') !== -1 ? 'mp4' : 'webm';
                        var blob = new Blob(chunks, { type: type });
                        var file = new File([blob], 'face-video.' + extension, {
                            type: type,
                            lastModified: Date.now()
                        });
                        var transfer = new DataTransfer();
                        transfer.items.add(file);
                        input.files = transfer.files;
                        input.dispatchEvent(new Event('change', { bubbles: true }));

                        if (previewUrl) {
                            URL.revokeObjectURL(previewUrl);
                        }

                        previewUrl = URL.createObjectURL(file);
                        preview.srcObject = null;
                        preview.src = previewUrl;
                        preview.muted = false;
                        preview.controls = true;
                        preview.play().catch(function () {});

                        var seconds = Math.max(1, Math.round((stoppedAt - startedAt) / 1000));
                        setState('recorded');
                        setStatus('Video attached (' + seconds + 's). Retake if you want another.');
                    };

                    recorder.start();
                    startedAt = Date.now();
                    stoppedAt = 0;
                    setState('recording');
                    prompt.textContent = prompts[0];
                    setStatus('Recording… 0s');

                    timer = window.setInterval(function () {
                        var seconds = Math.floor((Date.now() - startedAt) / 1000);
                        setStatus('Recording… ' + seconds + 's / ' + MAX_SECONDS + 's');

                        if (seconds >= MAX_SECONDS) {
                            finishRecording();
                        }
                    }, 250);

                    var step = 0;
                    promptTimer = window.setInterval(function () {
                        step += 1;

                        if (step < prompts.length) {
                            prompt.textContent = prompts[step];
                        } else {
                            window.clearInterval(promptTimer);
                            promptTimer = null;
                        }
                    }, PROMPT_MS);
                });

                function finishRecording() {
                    if (!recorder || recorder.state === 'inactive') {
                        return;
                    }

                    stoppedAt = Date.now();
                    clearTimers();
                    recorder.stop();
                }

                stopButton.addEventListener('click', function () {
                    var seconds = Math.floor((Date.now() - startedAt) / 1000);

                    if (seconds < MIN_SECONDS) {
                        showError('Keep recording until the prompts finish (' + MIN_SECONDS + 's) so all sides of your face are captured.');
                        return;
                    }

                    finishRecording();
                });

                retake.addEventListener('click', function () {
                    if (recorder && recorder.state === 'recording') {
                        clearTimers();
                        recorder.onstop = null;
                        recorder.stop();
                    }

                    stopStream();
                    input.value = '';
                    input.dispatchEvent(new Event('change', { bubbles: true }));

                    if (previewUrl) {
                        URL.revokeObjectURL(previewUrl);
                        previewUrl = null;
                    }

                    preview.srcObject = null;
                    preview.removeAttribute('src');
                    preview.controls = false;
                    preview.muted = true;
                    preview.load();

                    setState('idle');
                    setStatus('');
                    clearError();
                });

                window.addEventListener('pagehide', function () {
                    clearTimers();
                    stopStream();

                    if (previewUrl) {
                        URL.revokeObjectURL(previewUrl);
                    }
                });
            }

            setupFaceVideoCapture();
        })();
    </script>
@endpush
