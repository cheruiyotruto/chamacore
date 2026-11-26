<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    /**
     * File types that can be rendered inline in an authenticated page.
     * HTML/SVG and office formats are deliberately excluded.
     */
    public const PREVIEW_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'text/plain',
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ];

    public static function isPreviewable(?string $mime): bool
    {
        return in_array(strtolower((string) $mime), self::PREVIEW_MIMES, true);
    }

    /**
     * Server-detected MIME types mapped to a fixed, safe stored extension.
     * Client-supplied extensions and MIME types are never trusted.
     */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    public function store(
        Group $group,
        UploadedFile $file,
        array $data,
        User $actor,
        ?Member $member = null
    ): Document {
        if (! $file->isValid()) {
            throw new BusinessRuleException('The uploaded file is not valid.');
        }

        $maxKb = (int) config('chama.documents.max_size_kb', 10240);

        if ($file->getSize() > $maxKb * 1024) {
            throw new BusinessRuleException('The file exceeds the maximum upload size of '.$maxKb.' KB.');
        }

        $mime = Str::lower((string) $file->getMimeType());
        $allowed = config('chama.documents.allowed_mimes', array_keys(self::MIME_EXTENSIONS));

        if (! in_array($mime, $allowed, true) || ! isset(self::MIME_EXTENSIONS[$mime])) {
            throw new BusinessRuleException('This file type is not permitted.');
        }

        $this->assertContentMatchesSignature($file, $mime);

        $extension = self::MIME_EXTENSIONS[$mime];
        $directory = 'groups/'.$group->id.'/'.now()->format('Y/m');
        $stored = $file->storeAs($directory, Str::random(40).'.'.$extension, 'documents');

        if (! $stored) {
            throw new BusinessRuleException('The document could not be stored.');
        }

        $document = Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member?->id ?? $data['member_id'] ?? null,
            'documentable_type' => $data['documentable_type'] ?? null,
            'documentable_id' => $data['documentable_id'] ?? null,
            'type' => $data['type'] ?? 'other',
            'title' => $this->safeTitle($data['title'] ?? $file->getClientOriginalName()),
            'disk' => 'documents',
            'path' => $stored,
            'mime_type' => $mime,
            'size' => $file->getSize() ?: 0,
            'status' => 'pending',
            'visibility' => $data['visibility'] ?? 'staff',
            'uploaded_by' => $actor->id,
            'notes' => $data['notes'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        $this->logAccess($document, $actor, 'uploaded');

        AuditService::log('document.uploaded', [
            'document_id' => $document->id,
            'type' => $document->type,
            'title' => $document->title,
        ], $group);

        return $document;
    }

    public function verify(Document $document, User $actor): Document
    {
        $document->update([
            'status' => 'verified',
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ]);

        if ($document->member) {
            app(NotificationService::class)->send($document->member, 'document_verified', [
                'title' => $document->title,
            ]);
        }

        AuditService::log('document.verified', ['document_id' => $document->id], $document->group);

        return $document->refresh();
    }

    public function reject(Document $document, User $actor, string $reason): Document
    {
        $document->update([
            'status' => 'rejected',
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'notes' => $reason,
        ]);

        AuditService::log('document.rejected', [
            'document_id' => $document->id,
            'reason' => $reason,
        ], $document->group);

        return $document->refresh();
    }

    public function download(Document $document, User $actor)
    {
        $this->logAccess($document, $actor, 'downloaded');

        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw new BusinessRuleException('The stored file is missing.');
        }

        return Storage::disk($document->disk)->download($document->path, $document->title);
    }

    /**
     * Stream a document inline for authenticated review. Access is still
     * authorised by the controller and every view is recorded in the EDMS log.
     */
    public function preview(Document $document, User $actor)
    {
        if (! self::isPreviewable($document->mime_type)) {
            throw new BusinessRuleException('This file type cannot be previewed.');
        }

        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw new BusinessRuleException('The stored file is missing.');
        }

        $this->logAccess($document, $actor, 'viewed');

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->title,
            ['Content-Type' => strtolower((string) $document->mime_type)]
        );
    }

    public function delete(Document $document, User $actor): void
    {
        Storage::disk($document->disk)->delete($document->path);
        $this->logAccess($document, $actor, 'deleted');
        $document->delete();
    }

    /**
     * Basic content sniffing so a renamed executable cannot masquerade as a
     * PDF or image. This complements (rather than replaces) MIME detection.
     */
    private function assertContentMatchesSignature(UploadedFile $file, string $mime): void
    {
        $path = $file->getRealPath();

        if (! $path || ! is_readable($path)) {
            throw new BusinessRuleException('The uploaded file could not be inspected.');
        }

        $handle = fopen($path, 'rb');
        $header = $handle ? (string) fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($mime === 'application/pdf' && ! str_starts_with($header, '%PDF-')) {
            throw new BusinessRuleException('The file is not a valid PDF.');
        }

        if (str_starts_with($mime, 'image/') && @getimagesize($path) === false) {
            throw new BusinessRuleException('The file is not a valid image.');
        }

        if (str_contains($mime, 'openxmlformats') && ! str_starts_with($header, 'PK')) {
            throw new BusinessRuleException('The file is not a valid Office document.');
        }
    }

    private function safeTitle(mixed $title): string
    {
        $title = str_replace(['/', '\\', "\0", "\r", "\n"], '-', trim((string) $title));

        return mb_substr($title, 0, 180) ?: 'document';
    }

    private function logAccess(Document $document, User $actor, string $action): void
    {
        DocumentAccessLog::withoutGlobalScopes()->create([
            'group_id' => $document->group_id,
            'document_id' => $document->id,
            'user_id' => $actor->id,
            'action' => $action,
            'ip_address' => request()->ip(),
        ]);
    }
}
