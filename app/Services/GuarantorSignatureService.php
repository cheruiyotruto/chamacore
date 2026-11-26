<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\Group;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\Member;
use App\Support\SignatureCapture;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores a guarantor's acceptance signature in the group EDMS and links it to
 * the guarantee record rather than only to the member who signed it.
 */
class GuarantorSignatureService
{
    public function store(
        Guarantor $guarantor,
        Member $guarantorMember,
        Loan $loan,
        string $signature
    ): Document {
        $binary = SignatureCapture::decode($signature);
        $group = Group::withoutGlobalScopes()->findOrFail($guarantor->group_id);

        $directory = 'edms/groups/'.$group->id.'/members/'.Str::slug((string) $guarantorMember->member_no);
        $path = $directory.'/guarantee-signature-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.png';

        if (! Storage::disk('documents')->put($path, $binary)) {
            throw new BusinessRuleException('The guarantee signature could not be stored in the EDMS.');
        }

        $document = Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $guarantorMember->id,
            'documentable_type' => Guarantor::class,
            'documentable_id' => $guarantor->id,
            'type' => 'guarantee_signature',
            'title' => 'Guarantee signature · '.$loan->loan_no,
            'disk' => 'documents',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($binary),
            'status' => 'verified',
            'visibility' => 'staff',
            'uploaded_by' => $guarantorMember->user_id,
            'verified_by' => $guarantorMember->user_id,
            'verified_at' => now(),
            'notes' => 'Captured in the member portal after accepting guarantee #'.$guarantor->id.'.',
        ]);

        DocumentAccessLog::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'document_id' => $document->id,
            'user_id' => $guarantorMember->user_id,
            'action' => 'uploaded',
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);

        return $document;
    }
}
