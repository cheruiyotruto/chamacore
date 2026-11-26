<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use App\Support\JoinRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores member identity media and joining documents in the group's
 * electronic document management system (EDMS). Every file lives under the
 * member's permanent number so the physical and electronic records always
 * share one reference.
 */
class MemberEdmsService
{
    /**
     * @return array{photo: ?Document, video: ?Document}
     */
    public function storeIdentityMedia(
        Group $group,
        Member $member,
        User $uploader,
        ?UploadedFile $photo = null,
        ?UploadedFile $video = null,
        ?string $reference = null
    ): array {
        $directory = $this->directory($group, $member);
        $notes = trim('Reference '.$member->member_no.($reference ? ' · '.$reference : ''));

        $documents = ['photo' => null, 'video' => null];

        if ($photo) {
            $documents['photo'] = $this->storeFile(
                $group,
                $member,
                $uploader,
                $photo,
                $directory,
                'passport_photo',
                'Passport photo · '.$member->member_no,
                $notes,
                'passport-photo'
            );
        }

        if ($video) {
            $documents['video'] = $this->storeFile(
                $group,
                $member,
                $uploader,
                $video,
                $directory,
                'face_video',
                'Face verification video · '.$member->member_no,
                $notes,
                'face-video'
            );
        }

        return $documents;
    }

    /**
     * Store the documents and electronic signature an applicant submitted.
     *
     * @param  array<string, array<string, mixed>>  $requirements  enabled document definitions keyed by requirement key
     * @param  array<string, UploadedFile|null>  $uploads  uploaded files keyed by requirement key
     * @return array<string, array{key:string, label:string, type:string, document_id:int}>
     */
    public function storeApplicationDocuments(
        Group $group,
        Member $member,
        User $uploader,
        array $requirements,
        array $uploads,
        ?string $signature = null,
        ?string $reference = null,
        ?string $typedSignature = null,
        bool $typedConsent = false
    ): array {
        $directory = $this->directory($group, $member);
        $stored = [];

        foreach ($requirements as $key => $definition) {
            $notes = trim('Reference '.$member->member_no.' · joining requirement '.$key.($reference ? ' · '.$reference : ''));
            $label = (string) ($definition['label'] ?? $key);
            $type = (string) ($definition['type'] ?? 'other');

            if ($definition['signature'] ?? false) {
                if (! filled($signature)) {
                    continue;
                }

                $document = $this->storeSignature($group, $member, $uploader, (string) $signature, $directory, $notes);
            } elseif ($definition['typed_signature'] ?? false) {
                if (! filled($typedSignature)) {
                    continue;
                }

                if (! $typedConsent) {
                    throw new RuntimeException('The typed signature consent was not confirmed.');
                }

                $document = $this->storeTypedSignature(
                    $group,
                    $member,
                    $uploader,
                    (string) $typedSignature,
                    $directory,
                    $notes
                );
            } else {
                $file = $uploads[$key] ?? null;

                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $document = $this->storeFile(
                    $group,
                    $member,
                    $uploader,
                    $file,
                    $directory,
                    $type,
                    $label.' · '.$member->member_no,
                    $notes,
                    Str::slug($type) ?: 'document'
                );
            }

            $stored[$key] = [
                'key' => (string) $key,
                'label' => $label,
                'type' => $type,
                'document_id' => $document->id,
            ];
        }

        return $stored;
    }

    /**
     * Required documents recorded on the application that have not been
     * received, or whose document has since been removed or rejected.
     *
     * @return array<int, string>
     */
    public function missingRequiredDocuments(Member $member): array
    {
        $snapshot = $member->metadata['application_documents'] ?? null;

        if (! is_array($snapshot)) {
            return $this->missingBiometric($member);
        }

        $required = (array) ($snapshot['required'] ?? []);
        $submitted = (array) ($snapshot['submitted'] ?? []);

        if ($required === []) {
            return $this->missingBiometric($member);
        }

        $activeIds = Document::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('status', '!=', 'rejected')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $signatureIds = collect($submitted)
            ->filter(fn ($entry) => data_get($entry, 'type') === 'signature')
            ->pluck('document_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = [];

        foreach ($required as $requirement) {
            $key = (string) ($requirement['key'] ?? '');
            $documentId = (int) (data_get($submitted, $key.'.document_id') ?? 0);
            $candidateIds = [$documentId];

            // A drawn or typed signature satisfies the same requirement.
            if (($requirement['type'] ?? '') === 'signature') {
                $candidateIds = array_merge($candidateIds, $signatureIds);
            }

            if (array_intersect($candidateIds, $activeIds) !== []) {
                continue;
            }

            $missing[] = (string) ($requirement['label'] ?? $key);
        }

        return array_values(array_merge($missing, $this->missingBiometric($member, $missing)));
    }

    /**
     * @param  array<int, string>  $alreadyMissing
     * @return array<int, string>
     */
    private function missingBiometric(Member $member, array $alreadyMissing = []): array
    {
        // Administrators can require biometric capture for an individual
        // member after the original application snapshot was recorded.
        if (! $member->biometricRequired() || $member->hasBiometricCapture()) {
            return [];
        }

        return in_array('Biometric capture', $alreadyMissing, true)
            ? []
            : ['Biometric capture'];
    }

    private function storeSignature(
        Group $group,
        Member $member,
        User $uploader,
        string $signature,
        string $directory,
        string $notes
    ): Document {
        if (! preg_match('/^data:image\/png;base64,(.+)$/s', $signature, $matches)) {
            throw new RuntimeException('The electronic signature is not a valid PNG image.');
        }

        $binary = base64_decode($matches[1], true);

        if ($binary === false || $binary === '') {
            throw new RuntimeException('The electronic signature could not be decoded.');
        }

        $path = $directory.'/signature-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.png';

        if (! Storage::disk('documents')->put($path, $binary)) {
            throw new RuntimeException('The electronic signature could not be stored in the EDMS.');
        }

        return $this->recordDocument($group, $member, $uploader, [
            'type' => 'signature',
            'title' => 'Electronic signature · '.$member->member_no,
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($binary),
            'notes' => $notes,
        ]);
    }

    /**
     * Store a typed signature as a private EDMS record. The plain-text file
     * keeps the exact name and consent statement together so reviewers can see
     * what the applicant agreed to without needing a browser canvas image.
     */
    private function storeTypedSignature(
        Group $group,
        Member $member,
        User $uploader,
        string $name,
        string $directory,
        string $notes
    ): Document {
        $name = trim(strip_tags($name));

        if ($name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException('The typed signature is not valid.');
        }

        $content = implode("\n", [
            'Typed electronic signature',
            '',
            'Full name: '.$name,
            'Consent: I agree that typing my name above is my electronic signature and has the same effect as my handwritten signature.',
            'Submitted: '.now()->toIso8601String(),
            $notes,
            '',
        ]);

        $path = $directory.'/typed-signature-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.txt';

        if (! Storage::disk('documents')->put($path, $content)) {
            throw new RuntimeException('The typed signature could not be stored in the EDMS.');
        }

        return $this->recordDocument($group, $member, $uploader, [
            'type' => 'signature',
            'title' => $this->safeDocumentTitle('Typed electronic signature · '.$member->member_no),
            'path' => $path,
            'mime_type' => 'text/plain',
            'size' => strlen($content),
            'notes' => $notes,
        ]);
    }

    /**
     * Summarise which signature method the applicant used for member metadata.
     *
     * @param  array<string, array{key:string, label:string, type:string, document_id:int}>  $submitted
     * @return array{method:string, name:?string, consent:bool, document_id:int}|null
     */
    public function signatureMetadata(array $submitted, ?string $typedName = null): ?array
    {
        $typed = $submitted[JoinRequirements::TYPED_SIGNATURE_KEY] ?? null;

        if (is_array($typed)) {
            return [
                'method' => 'typed',
                'name' => mb_substr(trim(strip_tags((string) $typedName)), 0, 150),
                'consent' => true,
                'document_id' => (int) ($typed['document_id'] ?? 0),
            ];
        }

        $drawn = $submitted[JoinRequirements::SIGNATURE_KEY] ?? null;

        if (is_array($drawn)) {
            return [
                'method' => 'drawn',
                'name' => null,
                'consent' => false,
                'document_id' => (int) ($drawn['document_id'] ?? 0),
            ];
        }

        return null;
    }

    private function storeFile(
        Group $group,
        Member $member,
        User $uploader,
        UploadedFile $file,
        string $directory,
        string $type,
        string $title,
        string $notes,
        string $prefix
    ): Document {
        if (! $file->isValid()) {
            throw new RuntimeException('The uploaded identity file is not valid.');
        }

        $mime = Str::lower((string) $file->getMimeType());
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $filename = $prefix.'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(12)).'.'.$extension;

        $path = $file->storeAs($directory, $filename, 'documents');

        if (! $path) {
            throw new RuntimeException('The identity file could not be stored in the EDMS.');
        }

        return $this->recordDocument($group, $member, $uploader, [
            'type' => $type,
            'title' => $this->safeDocumentTitle($title),
            'path' => $path,
            'mime_type' => $mime ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordDocument(Group $group, Member $member, User $uploader, array $attributes): Document
    {
        $document = Document::withoutGlobalScopes()->create($attributes + [
            'group_id' => $group->id,
            'member_id' => $member->id,
            'documentable_type' => Member::class,
            'documentable_id' => $member->id,
            'disk' => 'documents',
            'status' => 'pending',
            'visibility' => 'staff',
            'uploaded_by' => $uploader->id,
        ]);

        DocumentAccessLog::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'document_id' => $document->id,
            'user_id' => $uploader->id,
            'action' => 'uploaded',
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);

        return $document;
    }

    private function directory(Group $group, Member $member): string
    {
        return 'edms/groups/'.$group->id.'/members/'.Str::slug($member->member_no);
    }

    private function safeDocumentTitle(string $title): string
    {
        $title = str_replace(['/', '\\', "\0", "\r", "\n"], '-', trim($title));

        return mb_substr($title, 0, 180) ?: 'document';
    }
}
