<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Support\SignatureCapture;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Handles the documents a member supplies when applying for an advance loan:
 * payslips, the employment letter and the member's online signature.
 *
 * Everything is stored in the group EDMS and linked to the loan so the loan
 * committee and the employer finance office see the same evidence.
 */
class AdvanceLoanDocumentService
{
    public const TYPE_PAYSLIP = 'advance_payslip';

    public const TYPE_EMPLOYMENT_LETTER = 'advance_employment_letter';

    public const TYPE_SIGNATURE = 'advance_loan_signature';

    public function __construct(private readonly DocumentService $documents)
    {
    }

    /**
     * @param  array<int, UploadedFile>  $payslips
     */
    public function storeApplicationDocuments(
        Loan $loan,
        Member $member,
        User $actor,
        array $payslips,
        ?UploadedFile $employmentLetter,
        ?string $signature
    ): void {
        $group = Group::withoutGlobalScopes()->findOrFail($loan->group_id);

        foreach ($payslips as $index => $file) {
            $this->documents->store($group, $file, [
                'member_id' => $member->id,
                'documentable_type' => Loan::class,
                'documentable_id' => $loan->id,
                'type' => self::TYPE_PAYSLIP,
                'title' => 'Payslip '.($index + 1).' · '.$loan->loan_no,
                'visibility' => 'staff',
                'notes' => 'Attached to the online advance loan application.',
            ], $actor, $member);
        }

        if ($employmentLetter) {
            $this->documents->store($group, $employmentLetter, [
                'member_id' => $member->id,
                'documentable_type' => Loan::class,
                'documentable_id' => $loan->id,
                'type' => self::TYPE_EMPLOYMENT_LETTER,
                'title' => 'Employment letter · '.$loan->loan_no,
                'visibility' => 'staff',
                'notes' => 'Attached to the online advance loan application.',
            ], $actor, $member);
        }

        if ($signature !== null && trim($signature) !== '') {
            $this->storeSignature($loan, $member, $actor, $signature);
        }
    }

    public function storeSignature(Loan $loan, Member $member, User $actor, string $signature): Document
    {
        $binary = SignatureCapture::decode($signature);
        $group = Group::withoutGlobalScopes()->findOrFail($loan->group_id);

        $directory = 'edms/groups/'.$group->id.'/loans';
        $path = $directory.'/advance-loan-signature-'.$loan->id.'-'.Str::lower(Str::random(6)).'.png';

        if (! Storage::disk('documents')->put($path, $binary)) {
            throw new BusinessRuleException('The advance loan signature could not be stored.');
        }

        $document = Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'documentable_type' => Loan::class,
            'documentable_id' => $loan->id,
            'type' => self::TYPE_SIGNATURE,
            'title' => 'Online signature · '.$loan->loan_no,
            'disk' => 'documents',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($binary),
            'status' => 'verified',
            'visibility' => 'staff',
            'uploaded_by' => $actor->id,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'notes' => 'Drawn by the member in the online advance loan application.',
        ]);

        DocumentAccessLog::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'document_id' => $document->id,
            'user_id' => $actor->id,
            'action' => 'uploaded',
            'ip_address' => request()->ip(),
        ]);

        return $document;
    }

    /**
     * @return array{payslips: bool, employment_letter: bool, signature: bool, complete: bool}
     */
    public function requirements(Loan $loan): array
    {
        $types = $loan->relationLoaded('documents')
            ? $loan->documents->pluck('type')->all()
            : Document::withoutGlobalScopes()
                ->where('documentable_type', Loan::class)
                ->where('documentable_id', $loan->id)
                ->pluck('type')
                ->all();

        $state = [
            'payslips' => in_array(self::TYPE_PAYSLIP, $types, true),
            'employment_letter' => in_array(self::TYPE_EMPLOYMENT_LETTER, $types, true),
            'signature' => in_array(self::TYPE_SIGNATURE, $types, true),
        ];

        $state['complete'] = $state['payslips'] && $state['employment_letter'] && $state['signature'];

        return $state;
    }
}
