<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Mail\EmployerFinanceRequestMail;
use App\Models\Document;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use App\Models\NotificationLog;
use App\Models\User;
use App\Support\EmployerFinance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Drives the employer-finance loan approval workflow for the members an
 * administrator has opted into it.
 *
 * 1. The member applies for a loan and downloads a pre-filled form.
 * 2. The employer's finance office stamps and signs the form.
 * 3. The member uploads the stamped copy.
 * 4. The finance office receives a secure link and confirms or declines.
 * 5. Only a confirmed employer approval allows disbursement.
 */
class EmployerFinanceService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
    ) {
    }

    public function settingsFor(Loan|Member|int $subject): EmployerFinance
    {
        $groupId = match (true) {
            $subject instanceof Loan => $subject->group_id,
            $subject instanceof Member => $subject->group_id,
            default => $subject,
        };

        return EmployerFinance::for(Group::withoutGlobalScopes()->find($groupId));
    }

    public function appliesTo(Member $member): bool
    {
        return $this->settingsFor($member)->appliesTo($member);
    }

    public function requiresForm(Loan $loan): bool
    {
        return (bool) $loan->employer_finance_required;
    }

    public function recordFormDownloaded(Loan $loan): void
    {
        if (! $this->requiresForm($loan)) {
            throw new BusinessRuleException('This loan does not require employer finance approval.');
        }

        if (! $loan->employer_finance_form_sent_at) {
            $loan->update(['employer_finance_form_sent_at' => now()]);
        }
    }

    /**
     * Signed links are short-lived and bound to the loan's current state. A
     * confirmation changes that state, which invalidates the link (single use).
     *
     * @return array<string, mixed>
     */
    public function signedParams(Loan $loan): array
    {
        return [
            'loan' => $loan->id,
            'state' => (string) ($loan->employer_finance_status ?? 'pending'),
            'v' => (int) ($loan->updated_at?->timestamp ?? 0),
        ];
    }

    public function confirmationUrl(Loan $loan): string
    {
        return URL::temporarySignedRoute(
            'employer-finance.confirm',
            now()->addHours(48),
            $this->signedParams($loan)
        );
    }

    public function confirmationStoreUrl(Loan $loan): string
    {
        return URL::temporarySignedRoute(
            'employer-finance.confirm.store',
            now()->addHours(48),
            $this->signedParams($loan)
        );
    }

    public function signedStateMatches(Loan $loan, mixed $state, mixed $version): bool
    {
        $params = $this->signedParams($loan);

        return hash_equals((string) $params['state'], (string) $state)
            && hash_equals((string) $params['v'], (string) $version);
    }

    public function storeStampedForm(Loan $loan, UploadedFile $file, User $actor): Document
    {
        if (! $this->requiresForm($loan)) {
            throw new BusinessRuleException('This loan does not require employer finance approval.');
        }

        if ($loan->employer_finance_status === EmployerFinance::STATUS_CONFIRMED) {
            throw new BusinessRuleException('The employer finance approval has already been confirmed.');
        }

        $group = Group::withoutGlobalScopes()->findOrFail($loan->group_id);
        $member = Member::withoutGlobalScopes()->findOrFail($loan->member_id);

        $document = $this->documents->store($group, $file, [
            'member_id' => $member->id,
            'documentable_type' => Loan::class,
            'documentable_id' => $loan->id,
            'type' => 'employer_finance_approval',
            'title' => 'Employer finance approval · '.$loan->loan_no,
            'visibility' => 'member',
            'notes' => 'Stamped and signed employer finance approval uploaded by the member.',
        ], $actor, $member);

        $loan->update([
            'employer_finance_status' => EmployerFinance::STATUS_FORM_UPLOADED,
            'employer_finance_notes' => null,
        ]);

        $this->notifications->send($member, 'employer_finance_form_uploaded', [
            'member_name' => $member->full_name,
            'loan_no' => $loan->loan_no,
            'amount' => \App\Support\Money::format($loan->requested_amount),
        ]);

        $this->notifyFinanceOffice($loan->refresh());

        AuditService::log('loan.employer_finance.form_uploaded', [
            'loan_id' => $loan->id,
            'document_id' => $document->id,
        ], $group);

        return $document;
    }

    public function sendToFinanceOffice(Loan $loan): bool
    {
        return $this->notifyFinanceOffice($loan);
    }

    public function confirm(
        Loan $loan,
        bool $approved,
        ?string $officerName = null,
        ?string $reference = null,
        ?string $notes = null
    ): Loan {
        if (! $this->requiresForm($loan)) {
            throw new BusinessRuleException('This loan does not require employer finance approval.');
        }

        if ($loan->employer_finance_status === EmployerFinance::STATUS_CONFIRMED && $approved) {
            throw new BusinessRuleException('The employer finance approval has already been confirmed.');
        }

        if ($loan->employer_finance_status !== EmployerFinance::STATUS_FORM_UPLOADED) {
            throw new BusinessRuleException('The stamped employer finance form has not been uploaded yet.');
        }

        return DB::transaction(function () use ($loan, $approved, $officerName, $reference, $notes) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);
            $group = Group::withoutGlobalScopes()->findOrFail($loan->group_id);
            $member = Member::withoutGlobalScopes()->findOrFail($loan->member_id);

            $loan->update([
                'employer_finance_status' => $approved
                    ? EmployerFinance::STATUS_CONFIRMED
                    : EmployerFinance::STATUS_DECLINED,
                'employer_finance_confirmed_at' => now(),
                'employer_finance_officer_name' => $officerName,
                'employer_finance_reference' => $reference,
                'employer_finance_notes' => $notes,
            ]);

            if ($approved) {
                $this->notifications->send($member, 'employer_finance_confirmed', [
                    'member_name' => $member->full_name,
                    'loan_no' => $loan->loan_no,
                ]);
            } else {
                $this->notifications->send($member, 'employer_finance_declined', [
                    'member_name' => $member->full_name,
                    'loan_no' => $loan->loan_no,
                    'comments' => $notes ?? '',
                ]);
            }

            AuditService::log('loan.employer_finance.'.($approved ? 'confirmed' : 'declined'), [
                'loan_id' => $loan->id,
                'officer_name' => $officerName,
                'reference' => $reference,
                'notes' => $notes,
            ], $group);

            return $loan->refresh();
        });
    }

    public function assertDisbursable(Loan $loan): void
    {
        if (! $this->requiresForm($loan)) {
            return;
        }

        if ($loan->employer_finance_status !== EmployerFinance::STATUS_CONFIRMED) {
            throw new BusinessRuleException(
                'The employer finance office must confirm the stamped form before this loan can be disbursed.'
            );
        }
    }

    private function notifyFinanceOffice(Loan $loan): bool
    {
        $settings = $this->settingsFor($loan);

        if (! $settings->enabled()) {
            return false;
        }

        $email = $settings->email();

        if ($email === '') {
            Log::info('employer_finance.no_finance_email', ['loan_id' => $loan->id]);

            return false;
        }

        $confirmationUrl = $this->confirmationUrl($loan);

        try {
            Mail::to($email)->send(new EmployerFinanceRequestMail($loan, $confirmationUrl));

            NotificationLog::withoutGlobalScopes()->create([
                'group_id' => $loan->group_id,
                'member_id' => $loan->member_id,
                'channel' => 'email',
                'recipient' => $email,
                'template_key' => 'employer_finance_request',
                'payload' => [
                    'loan_id' => $loan->id,
                    'loan_no' => $loan->loan_no,
                ],
                'status' => 'sent',
                'sent_at' => now(),
                'created_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('employer_finance.mail.failed', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);

            NotificationLog::withoutGlobalScopes()->create([
                'group_id' => $loan->group_id,
                'member_id' => $loan->member_id,
                'channel' => 'email',
                'recipient' => $email,
                'template_key' => 'employer_finance_request',
                'payload' => [
                    'loan_id' => $loan->id,
                    'loan_no' => $loan->loan_no,
                ],
                'status' => 'failed',
                'error' => $e->getMessage(),
                'created_at' => now(),
            ]);

            return false;
        }
    }
}
