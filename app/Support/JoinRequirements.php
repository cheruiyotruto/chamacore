<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;

/**
 * Resolves the group administrator's choices for the public membership
 * application form. Every field can be hidden, optional or mandatory, the
 * identity media requirement can be a photo, a face video, either or both,
 * and each joining document (signed form, KRA PIN, ID copies, drawn or typed
 * electronic signature and custom uploads) can be enabled and required
 * independently. Administrators can also publish free-text joining details.
 */
class JoinRequirements
{
    public const SETTING_KEY = 'join_requirements';

    public const SIGNATURE_KEY = 'electronic_signature';

    public const TYPED_SIGNATURE_KEY = 'typed_signature';

    public const BIOMETRIC_KEY = 'biometric_template';

    public const JOINING_DETAILS_MAX = 2000;

    public const SIGNATURE_KEYS = [self::SIGNATURE_KEY, self::TYPED_SIGNATURE_KEY];

    public const MAX_CUSTOM_DOCUMENTS = 10;

    /**
     * Points shown in the "What the invitee provides" card on the member
     * portal's invitation page. Group admins can hide the whole card or any
     * individual point without changing what the public application collects.
     */
    public const PORTAL_POINTS = [
        'details' => 'Personal, contact and next-of-kin details.',
        'identity' => 'A passport photo and/or face video, stored in the EDMS under their member number.',
        'biometric' => 'An optional biometric capture, which administrators can require for a specific invitation.',
        'approval' => 'Their application then waits for a group official to approve it.',
    ];

    public const IDENTITY_MODES = [
        'none' => 'Not required',
        'either' => 'At least one selected media',
        'photo' => 'Passport photo mandatory',
        'video' => 'Face video mandatory',
        'both' => 'Both photo and video mandatory',
    ];

    public const FIELDS = [
        'first_name' => ['label' => 'First name', 'group' => 'Personal details', 'fixed' => true, 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:100']],
        'middle_name' => ['label' => 'Middle name', 'group' => 'Personal details', 'enabled' => true, 'required' => false, 'rules' => ['string', 'max:100']],
        'last_name' => ['label' => 'Last name', 'group' => 'Personal details', 'fixed' => true, 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:100']],
        'email' => ['label' => 'Email address', 'group' => 'Personal details', 'fixed' => true, 'enabled' => true, 'required' => true, 'rules' => ['email', 'max:190']],
        'national_id' => ['label' => 'National ID / passport number', 'group' => 'Personal details', 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:40']],
        'kra_pin_number' => ['label' => 'KRA PIN number', 'group' => 'Personal details', 'enabled' => true, 'required' => false, 'rules' => ['string', 'max:30', 'not_regex:/[<>\r\n]/']],
        'gender' => ['label' => 'Gender', 'group' => 'Personal details', 'enabled' => true, 'required' => true, 'rules' => ['in:male,female,other']],
        'date_of_birth' => ['label' => 'Date of birth', 'group' => 'Personal details', 'enabled' => true, 'required' => true, 'rules' => ['date', 'before:today']],
        'phone' => ['label' => 'Phone number', 'group' => 'Contact and work', 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:30']],
        'occupation' => ['label' => 'Occupation', 'group' => 'Contact and work', 'enabled' => true, 'required' => false, 'rules' => ['string', 'max:120']],
        'employer' => ['label' => 'Employer / business', 'group' => 'Contact and work', 'enabled' => true, 'required' => false, 'rules' => ['string', 'max:120']],
        'employer_address' => ['label' => 'Work address', 'group' => 'Contact and work', 'enabled' => true, 'required' => false, 'rules' => ['string', 'max:500']],
        'workstation' => ['label' => 'Workstation / work location', 'group' => 'Contact and work', 'enabled' => true, 'required' => false, 'rules' => ['string', 'max:150']],
        'address' => ['label' => 'Home address', 'group' => 'Contact and work', 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:500']],
        'membership_category' => ['label' => 'Membership category', 'group' => 'Contact and work', 'enabled' => true, 'required' => false, 'rules' => ['in:ordinary,associate,honorary']],
        'next_of_kin_name' => ['label' => 'Next of kin name', 'group' => 'Next of kin', 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:150']],
        'next_of_kin_relationship' => ['label' => 'Next of kin relationship', 'group' => 'Next of kin', 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:60']],
        'next_of_kin_phone' => ['label' => 'Next of kin phone', 'group' => 'Next of kin', 'enabled' => true, 'required' => true, 'rules' => ['string', 'max:30']],
    ];

    public const MEDIA = [
        'passport_photo' => ['label' => 'Passport photo', 'rules' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']],
        'face_video' => ['label' => 'Face video', 'rules' => ['file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:20480']],
    ];

    /**
     * Documents an applicant can be asked to submit before approval. Each one
     * is stored in the EDMS against the member's permanent number.
     */
    public const DOCUMENTS = [
        'signed_application' => [
            'label' => 'Signed application form',
            'type' => 'membership_form',
            'help' => 'Upload the printed application form showing your handwritten signature. PDF, JPG, PNG or WEBP · up to 10 MB.',
            'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'enabled' => false,
            'required' => false,
        ],
        'electronic_signature' => [
            'label' => 'Electronic signature',
            'type' => 'signature',
            'help' => 'Sign directly on the screen with your mouse or finger. The signature is saved as an image in your file.',
            'rules' => ['string', 'max:2000000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=\r\n]+$/'],
            'signature' => true,
            'enabled' => true,
            'required' => false,
        ],
        'typed_signature' => [
            'label' => 'Typed signature',
            'type' => 'signature',
            'help' => 'Type your full legal name and confirm that you intend it as your electronic signature.',
            'rules' => ['string', 'max:150', 'regex:/\S/u', 'not_regex:/[<>\r\n]/'],
            'typed_signature' => true,
            'enabled' => false,
            'required' => false,
        ],
        'kra_pin_certificate' => [
            'label' => 'KRA PIN certificate',
            'type' => 'kra_pin',
            'help' => 'Upload your KRA PIN certificate. PDF, JPG, PNG or WEBP · up to 10 MB.',
            'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'enabled' => false,
            'required' => false,
        ],
        'national_id_copy' => [
            'label' => 'National ID copy (front)',
            'type' => 'national_id',
            'help' => 'Upload a clear copy of the front of your national ID or passport. PDF, JPG, PNG or WEBP · up to 10 MB.',
            'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'enabled' => true,
            'required' => false,
        ],
        'national_id_copy_back' => [
            'label' => 'National ID copy (back)',
            'type' => 'national_id_back',
            'help' => 'Upload a clear copy of the back of your national ID. PDF, JPG, PNG or WEBP · up to 10 MB.',
            'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'enabled' => true,
            'required' => false,
        ],
        'passport_copy' => [
            'label' => 'Passport copy',
            'type' => 'passport',
            'help' => 'Upload the photo page of your passport. PDF, JPG, PNG or WEBP · up to 10 MB.',
            'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'enabled' => false,
            'required' => false,
        ],
        self::BIOMETRIC_KEY => [
            'label' => 'Biometric capture',
            'type' => 'biometric',
            'help' => 'Upload the fingerprint or face template/scan from your verification device. Optional unless your group requires it for your application. PDF, JPG, PNG or WEBP · up to 10 MB.',
            'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'biometric' => true,
            'enabled' => true,
            'required' => false,
        ],
    ];

    /** @var array{fields: array<string, array{enabled:bool, required:bool}>, media: array<string, array{enabled:bool}>, documents: array<string, array{enabled:bool, required:bool}>, custom_documents: array<int, array{key:string, label:string, enabled:bool, required:bool}>, identity_mode: string, joining_details: string, portal_points_enabled: bool, portal_points: array<string, bool>} */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = self::normalize($config);
    }

    public static function for(?Group $group): self
    {
        if (! $group) {
            return new self;
        }

        $stored = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', self::SETTING_KEY)
            ->first()?->value;

        return new self(is_array($stored) ? $stored : []);
    }

    /**
     * @return array{fields: array<string, array{enabled:bool, required:bool}>, media: array<string, array{enabled:bool}>, documents: array<string, array{enabled:bool, required:bool}>, custom_documents: array<int, array{key:string, label:string, enabled:bool, required:bool}>, identity_mode: string, joining_details: string, portal_points_enabled: bool, portal_points: array<string, bool>}
     */
    public static function defaults(): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $definition) {
            $fields[$key] = [
                'enabled' => (bool) ($definition['enabled'] ?? true),
                'required' => (bool) ($definition['required'] ?? false),
            ];
        }

        $media = [];
        foreach (self::MEDIA as $key => $definition) {
            $media[$key] = ['enabled' => true];
        }

        $documents = [];
        foreach (self::DOCUMENTS as $key => $definition) {
            $documents[$key] = [
                'enabled' => (bool) ($definition['enabled'] ?? false),
                'required' => (bool) ($definition['required'] ?? false),
            ];
        }

        return [
            'fields' => $fields,
            'media' => $media,
            'documents' => $documents,
            'custom_documents' => [],
            'identity_mode' => 'either',
            'joining_details' => '',
            'portal_points_enabled' => true,
            'portal_points' => array_fill_keys(array_keys(self::PORTAL_POINTS), true),
        ];
    }

    /**
     * Normalise untrusted settings input into a complete, safe configuration.
     *
     * @param  array<string, mixed>  $input
     * @return array{fields: array<string, array{enabled:bool, required:bool}>, media: array<string, array{enabled:bool}>, documents: array<string, array{enabled:bool, required:bool}>, custom_documents: array<int, array{key:string, label:string, enabled:bool, required:bool}>, identity_mode: string, joining_details: string, portal_points_enabled: bool, portal_points: array<string, bool>}
     */
    public static function normalize(array $input): array
    {
        $config = self::defaults();

        foreach (self::FIELDS as $key => $definition) {
            $row = (array) data_get($input, 'fields.'.$key, []);
            $fixed = (bool) ($definition['fixed'] ?? false);

            $enabled = $fixed || self::toBool($row['enabled'] ?? $config['fields'][$key]['enabled']);
            $required = $fixed || ($enabled && self::toBool($row['required'] ?? $config['fields'][$key]['required']));

            $config['fields'][$key] = ['enabled' => $enabled, 'required' => $required];
        }

        foreach (self::MEDIA as $key => $definition) {
            $row = (array) data_get($input, 'media.'.$key, []);
            $config['media'][$key] = [
                'enabled' => self::toBool($row['enabled'] ?? $config['media'][$key]['enabled']),
            ];
        }

        foreach (self::DOCUMENTS as $key => $definition) {
            $row = (array) data_get($input, 'documents.'.$key, []);
            $enabled = self::toBool($row['enabled'] ?? $config['documents'][$key]['enabled']);
            $config['documents'][$key] = [
                'enabled' => $enabled,
                'required' => $enabled && self::toBool($row['required'] ?? $config['documents'][$key]['required']),
            ];
        }

        $config['custom_documents'] = self::normalizeCustomDocuments(data_get($input, 'custom_documents', []));

        $mode = (string) data_get($input, 'identity_mode', $config['identity_mode']);
        $config['identity_mode'] = array_key_exists($mode, self::IDENTITY_MODES) ? $mode : 'either';

        $photo = $config['media']['passport_photo']['enabled'] ?? false;
        $video = $config['media']['face_video']['enabled'] ?? false;

        if ($config['identity_mode'] === 'photo' && ! $photo) {
            $config['identity_mode'] = $video ? 'either' : 'none';
        }

        if ($config['identity_mode'] === 'video' && ! $video) {
            $config['identity_mode'] = $photo ? 'either' : 'none';
        }

        if ($config['identity_mode'] === 'both' && (! $photo || ! $video)) {
            $config['identity_mode'] = ($photo || $video) ? 'either' : 'none';
        }

        if ($config['identity_mode'] === 'either' && ! $photo && ! $video) {
            $config['identity_mode'] = 'none';
        }

        $details = data_get($input, 'joining_details', '');
        $details = is_string($details) ? trim(strip_tags($details)) : '';
        $config['joining_details'] = mb_substr($details, 0, self::JOINING_DETAILS_MAX);

        $config['portal_points_enabled'] = self::toBool(
            data_get($input, 'portal_points_enabled', $config['portal_points_enabled'])
        );

        foreach (array_keys(self::PORTAL_POINTS) as $key) {
            $config['portal_points'][$key] = self::toBool(
                data_get($input, 'portal_points.'.$key, $config['portal_points'][$key])
            );
        }

        return $config;
    }

    public function fieldEnabled(string $key): bool
    {
        return (bool) data_get($this->config, 'fields.'.$key.'.enabled', false);
    }

    public function fieldRequired(string $key): bool
    {
        return $this->fieldEnabled($key) && (bool) data_get($this->config, 'fields.'.$key.'.required', false);
    }

    public function mediaEnabled(string $key): bool
    {
        return (bool) data_get($this->config, 'media.'.$key.'.enabled', false);
    }

    public function identityMode(): string
    {
        return (string) ($this->config['identity_mode'] ?? 'none');
    }

    public function joiningDetails(): string
    {
        return (string) ($this->config['joining_details'] ?? '');
    }

    public function portalPointsVisible(): bool
    {
        return (bool) ($this->config['portal_points_enabled'] ?? true);
    }

    /**
     * Labels for the points currently enabled for the member portal card.
     *
     * @return array<int, string>
     */
    public function visiblePortalPoints(): array
    {
        if (! $this->portalPointsVisible()) {
            return [];
        }

        $points = [];

        foreach (self::PORTAL_POINTS as $key => $label) {
            if ((bool) data_get($this->config, 'portal_points.'.$key, true)) {
                $points[] = $label;
            }
        }

        return $points;
    }

    public function anyMediaEnabled(): bool
    {
        foreach (array_keys(self::MEDIA) as $key) {
            if ($this->mediaEnabled($key)) {
                return true;
            }
        }

        return false;
    }

    public function documentEnabled(string $key): bool
    {
        return (bool) data_get($this->config, 'documents.'.$key.'.enabled', false);
    }

    public function documentRequired(string $key): bool
    {
        return $this->documentEnabled($key) && (bool) data_get($this->config, 'documents.'.$key.'.required', false);
    }

    /**
     * Return a copy of these requirements with biometric capture enabled and
     * its required state overridden. Used when an invitation is issued to one
     * specific applicant.
     */
    public function withBiometricRequirement(bool $required): self
    {
        $config = $this->config;
        $config['documents'][self::BIOMETRIC_KEY] = [
            'enabled' => true,
            'required' => $required,
        ];

        return new self($config);
    }

    /**
     * @return array<int, array{key:string, label:string, enabled:bool, required:bool}>
     */
    public function customDocuments(): array
    {
        return (array) ($this->config['custom_documents'] ?? []);
    }

    /**
     * Every document the applicant can submit right now, catalogue entries
     * first, then administrator-defined custom documents.
     *
     * @return array<string, array<string, mixed>>
     */
    public function enabledDocuments(): array
    {
        $documents = [];

        foreach (self::DOCUMENTS as $key => $definition) {
            if (! $this->documentEnabled($key)) {
                continue;
            }

            $documents[$key] = array_merge($definition, ['required' => $this->documentRequired($key)]);
        }

        foreach ($this->customDocuments() as $row) {
            if (! $row['enabled']) {
                continue;
            }

            $documents[$row['key']] = [
                'label' => $row['label'],
                'type' => 'other',
                'help' => 'Upload the requested document. PDF, JPG, PNG or WEBP · up to 10 MB.',
                'rules' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
                'enabled' => true,
                'required' => (bool) $row['required'],
                'custom' => true,
            ];
        }

        return $documents;
    }

    public function anyDocumentsEnabled(): bool
    {
        return $this->enabledDocuments() !== [];
    }

    /**
     * The documents that must be received before the application can be
     * approved. Stored on the member so later settings changes do not move the
     * goalposts for an application already submitted.
     *
     * @return array<int, array{key:string, label:string, type:string}>
     */
    public function requiredDocuments(): array
    {
        $required = [];
        $signatureRequired = false;

        foreach ($this->enabledDocuments() as $key => $definition) {
            if (! ($definition['required'] ?? false)) {
                continue;
            }

            // Drawn and typed signatures are alternative ways to satisfy one
            // electronic-signature requirement, never two separate documents.
            if ($this->isSignatureDocument($definition)) {
                $signatureRequired = true;

                continue;
            }

            $required[] = [
                'key' => (string) $key,
                'label' => (string) $definition['label'],
                'type' => (string) $definition['type'],
            ];
        }

        if ($signatureRequired) {
            array_unshift($required, [
                'key' => 'signature',
                'label' => 'Electronic signature',
                'type' => 'signature',
            ]);
        }

        return $required;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array
    {
        $rules = ['consent' => ['accepted']];

        foreach (self::FIELDS as $key => $definition) {
            if (! $this->fieldEnabled($key)) {
                continue;
            }

            $rules[$key] = array_merge(
                [$this->fieldRequired($key) ? 'required' : 'nullable'],
                $definition['rules']
            );
        }

        foreach (self::MEDIA as $key => $definition) {
            if ($this->mediaEnabled($key)) {
                $rules[$key] = array_merge(['nullable'], $definition['rules']);
            }
        }

        foreach ($this->enabledDocuments() as $key => $definition) {
            if ($this->isSignatureDocument($definition)) {
                continue;
            }

            $presence = ($definition['required'] ?? false) ? 'required' : 'nullable';
            $rules['documents.'.$key] = array_merge([$presence], $definition['rules']);
        }

        if ($this->documentEnabled(self::SIGNATURE_KEY)) {
            $presence = $this->documentRequired(self::SIGNATURE_KEY)
                ? ($this->documentEnabled(self::TYPED_SIGNATURE_KEY) ? 'required_without:typed_signature' : 'required')
                : 'nullable';

            $rules['signature'] = array_merge([$presence], self::DOCUMENTS[self::SIGNATURE_KEY]['rules']);
        }

        if ($this->documentEnabled(self::TYPED_SIGNATURE_KEY)) {
            $presence = $this->documentRequired(self::TYPED_SIGNATURE_KEY)
                ? ($this->documentEnabled(self::SIGNATURE_KEY) ? 'required_without:signature' : 'required')
                : 'nullable';

            $rules['typed_signature'] = array_merge([$presence], self::DOCUMENTS[self::TYPED_SIGNATURE_KEY]['rules']);
            $rules['typed_signature_consent'] = ['required_with:typed_signature', 'accepted'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function identityErrors(bool $hasPhoto, bool $hasVideo): array
    {
        return match ($this->identityMode()) {
            'photo' => $hasPhoto ? [] : ['passport_photo' => 'A passport photo is required for this group.'],
            'video' => $hasVideo ? [] : ['face_video' => 'A short face video is required for this group.'],
            'both' => $hasPhoto && $hasVideo ? [] : ['passport_photo' => 'Both a passport photo and a face video are required for this group.'],
            'either' => $hasPhoto || $hasVideo ? [] : ['passport_photo' => 'Upload a passport photo or a short face video for identity verification.'],
            default => [],
        };
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function groupedFields(): array
    {
        $grouped = [];

        foreach (self::FIELDS as $key => $definition) {
            $grouped[$definition['group']][$key] = $definition;
        }

        return $grouped;
    }

    /**
     * @return array{fields: array<string, array{enabled:bool, required:bool}>, media: array<string, array{enabled:bool}>, documents: array<string, array{enabled:bool, required:bool}>, custom_documents: array<int, array{key:string, label:string, enabled:bool, required:bool}>, identity_mode: string, joining_details: string, portal_points_enabled: bool, portal_points: array<string, bool>}
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @return array<int, array{key:string, label:string, enabled:bool, required:bool}>
     */
    private static function normalizeCustomDocuments(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $documents = [];

        foreach (array_slice(array_values($rows), 0, self::MAX_CUSTOM_DOCUMENTS) as $row) {
            $row = (array) $row;
            $label = trim(strip_tags((string) ($row['label'] ?? '')));

            if ($label === '') {
                continue;
            }

            $enabled = self::toBool($row['enabled'] ?? true);

            $documents[] = [
                'key' => 'custom_'.(count($documents) + 1),
                'label' => mb_substr($label, 0, 120),
                'enabled' => $enabled,
                'required' => $enabled && self::toBool($row['required'] ?? false),
            ];
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isSignatureDocument(array $definition): bool
    {
        return (bool) (($definition['signature'] ?? false) || ($definition['typed_signature'] ?? false));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
