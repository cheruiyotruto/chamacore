<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ApprovalWorkflow;
use App\Models\Communication;
use App\Models\Document;
use App\Models\Group;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Services\Loans\LoanCalculator;
use App\Services\Loans\LoanQuote;
use App\Support\EmployerFinance;
use App\Support\GuaranteeSettings;
use App\Support\Money;
use App\Support\SignatureCapture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanOriginationService
{
    public function __construct(
        private readonly LoanCalculator $calculator,
        private readonly MultiplierEngine $multiplier,
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
        private readonly GuarantorSignatureService $signatures,
        private readonly InvitationService $invitations,
    ) {
    }

    /**
     * Produce the complete borrower-facing quotation before acceptance.
     *
     * @return array<string, mixed>
     */
    public function quotation(
        Member $member,
        LoanProduct $product,
        int $amount,
        int $duration,
        ?string $durationUnit = null
    ): array {
        $durationUnit ??= $product->duration_unit;
        $start = CarbonImmutable::now()->startOfDay();
        $fees = $this->feeTotal($product, $amount);

        $quote = $this->calculator->quote($this->calculatorTerms($product, $amount, $duration, $durationUnit, $start, $fees));

        return [
            'quote' => $quote,
            'quote_array' => $quote->toArray(),
            'fees' => $fees,
            'multiplier' => $this->multiplier->calculate($member, $product, $amount),
        ];
    }

    /**
     * @param array{
     *   amount:int, duration:int, duration_unit?:string, purpose?:string,
     *   purpose_description?:string,
     *   guarantors?:array<int, array{member_id?:int, email?:string, phone?:string, channels?:array<int,string>, amount?:int}>,
     *   documents?:array
     * } $data
     */
    public function apply(Member $member, LoanProduct $product, array $data, User $actor): Loan
    {
        $amount = (int) $data['amount'];
        $duration = (int) $data['duration'];
        $durationUnit = $data['duration_unit'] ?? $product->duration_unit;

        if (! $product->is_active) {
            throw new BusinessRuleException('This loan product is not available.');
        }

        if ($amount < $product->min_amount) {
            throw new BusinessRuleException('The minimum amount for this product is '.Money::format($product->min_amount).'.');
        }

        if ($amount > $product->max_amount) {
            throw new BusinessRuleException('The maximum amount for this product is '.Money::format($product->max_amount).'.');
        }

        $this->assertDuration($product, $duration, $durationUnit);

        $quote = $this->calculator->quote($this->calculatorTerms(
            $product,
            $amount,
            $duration,
            $durationUnit,
            CarbonImmutable::now()->startOfDay(),
            $this->feeTotal($product, $amount)
        ));

        $capacity = $this->multiplier->calculate($member, $product, $amount);

        if (! $capacity['eligible']) {
            throw new BusinessRuleException(implode(' ', $capacity['blockers']));
        }

        $guarantorInputs = collect($data['guarantors'] ?? [])
            ->filter(fn ($input) => is_array($input))
            ->values();

        $guarantorMode = $product->guarantorMode();

        if ($guarantorMode === 'none' && $guarantorInputs->isNotEmpty()) {
            throw new BusinessRuleException('This loan product does not use guarantors.');
        }

        if ($guarantorMode === 'required' && $guarantorInputs->count() < $product->min_guarantors) {
            throw new BusinessRuleException("This product requires at least {$product->min_guarantors} guarantor(s).");
        }

        $group = Group::withoutGlobalScopes()->findOrFail($member->group_id);
        $guaranteeSettings = GuaranteeSettings::for($group);

        // A member who is already guaranteeing another loan may only borrow
        // against the savings left after that guarantee is reserved. The
        // remainder has to cover principal plus every charge; additional
        // guarantors (or an eligible self-guarantee) may cover the shortfall.
        // This guarantee-coverage rule is optional per loan: it applies to
        // required-guarantor loans and to optional loans that include guarantors.
        // Pure multiplier loans (no guarantors used) rely on multiplier capacity.
        $remainingSavings = $this->multiplier->remainingSavings($member, $product);
        $usesGuaranteeCoverage = $guarantorMode === 'required'
            || ($guarantorMode === 'optional' && $guarantorInputs->isNotEmpty());

        if ($capacity['guarantee_commitments'] > 0 && $usesGuaranteeCoverage) {
            $this->assertGuaranteeBackedBorrowing(
                $member,
                $amount,
                $quote->totalRepayable,
                $remainingSavings,
                $guarantorInputs
            );
        }

        return DB::transaction(function () use ($member, $product, $actor, $data, $amount, $duration, $durationUnit, $quote, $capacity, $guarantorInputs, $guaranteeSettings, $group, $guarantorMode) {
            $employerFinance = EmployerFinance::for($group)->appliesTo($member)
                || $product->requiresEmployerRemittance();

            $loan = Loan::withoutGlobalScopes()->create([
                'group_id' => $member->group_id,
                'member_id' => $member->id,
                'loan_product_id' => $product->id,
                'status' => Loan::STATUS_SUBMITTED,
                'category' => $product->category,
                'requested_amount' => $amount,
                'duration' => $duration,
                'duration_unit' => $durationUnit,
                'repayment_frequency' => $product->repayment_frequency,
                'interest_rate_bps' => $product->interest_rate_bps,
                'interest_period' => $product->interest_period,
                'interest_method' => $product->interest_method,
                'accrual_mode' => $product->accrual_mode,
                'day_count' => $product->day_count,
                'purpose' => $data['purpose'] ?? null,
                'purpose_description' => $data['purpose_description'] ?? null,
                'employer_finance_required' => $employerFinance,
                'employer_finance_status' => $employerFinance
                    ? EmployerFinance::STATUS_PENDING_FORM
                    : null,
                'multiplier_breakdown' => $capacity,
                'terms_snapshot' => [
                    'product' => $product->only([
                        'id', 'name', 'code', 'category', 'interest_method', 'interest_rate_bps',
                        'interest_period', 'accrual_mode', 'day_count', 'repayment_frequency',
                        'processing_fee_bps', 'insurance_fee_bps', 'allocation_policy',
                        'penalty_rate_bps', 'penalty_period', 'penalty_grace_days',
                    ]),
                    'requires_employer_remittance' => $product->requiresEmployerRemittance(),
                    'quotation' => $quote->toArray(),
                    'requested_amount' => $amount,
                    'duration' => $duration,
                    'duration_unit' => $durationUnit,
                    'advance_application' => $this->sanitizeAdvanceApplication($data['advance_application'] ?? null),
                ],
                'submitted_at' => now(),
                'created_by' => $actor->id,
            ]);

            LoanApproval::withoutGlobalScopes()->create([
                'group_id' => $member->group_id,
                'loan_id' => $loan->id,
                'step_index' => 0,
                'role_key' => 'member',
                'actor_id' => $actor->id,
                'action' => 'submitted',
                'comments' => 'Loan application submitted.',
                'acted_at' => now(),
            ]);

            $guarantorCount = 0;

            foreach ($guarantorInputs as $input) {
                $memberId = $input['member_id'] ?? null;
                $email = filled($input['email'] ?? null)
                    ? strtolower(trim((string) $input['email'])) : null;
                $phone = filled($input['phone'] ?? null)
                    ? trim((string) $input['phone']) : null;

                if (! $memberId && ! $email && ! $phone) {
                    throw new BusinessRuleException('Each guarantor needs a member, email address or phone number.');
                }

                if ($memberId) {
                    $guarantorMember = Member::withoutGlobalScopes()
                        ->where('group_id', $member->group_id)
                        ->where('id', $memberId)
                        ->where('status', 'active')
                        ->first();

                    if (! $guarantorMember) {
                        throw new BusinessRuleException('One of the selected guarantors is not an active member of this group.');
                    }
                } else {
                    // A contact may already belong to an active member. Link
                    // directly to that member instead of sending a join invite.
                    $guarantorMember = Member::withoutGlobalScopes()
                        ->where('group_id', $member->group_id)
                        ->where('status', 'active')
                        ->where(function ($query) use ($email, $phone) {
                            if ($email) {
                                $query->orWhere('email', $email);
                            }
                            if ($phone) {
                                $query->orWhere('phone', $phone);
                            }
                        })
                        ->first();
                }

                if ($guarantorMember && $guarantorMember->id === $member->id) {
                    throw new BusinessRuleException('A member cannot guarantee their own loan.');
                }

                $principalAmount = (int) ceil(
                    $amount * $product->guarantor_coverage_bps / 10000 / max(1, $guarantorInputs->count())
                );
                $principalAmount = (int) ($input['amount'] ?? $principalAmount);

                // The quotation already contains the complete contractual
                // repayment: principal + scheduled interest + configured fees.
                // Late penalties are deliberately excluded because they are not
                // known at application time.
                $fullObligationAmount = (int) ceil(
                    $quote->totalRepayable * $principalAmount / max(1, $amount)
                );
                $guaranteeAmount = $guaranteeSettings->coversFullObligationForNewGuarantees()
                    ? $fullObligationAmount
                    : $principalAmount;

                $invitation = null;
                $requiredSavings = null;
                $capacityAtRequest = null;
                $requestedAt = null;
                $invitationSentAt = null;

                if ($guarantorMember) {
                    $memberCapacity = $this->multiplier->guarantorCapacity($guarantorMember, $product);
                    if ($memberCapacity['available'] < $guaranteeAmount) {
                        throw new BusinessRuleException(
                            "{$guarantorMember->full_name} does not have sufficient guarantee capacity for ".Money::format($guaranteeAmount).'.'
                        );
                    }

                    $capacityAtRequest = $memberCapacity['available'];
                    $requestedAt = now();
                    $status = Guarantor::STATUS_PENDING;
                } else {
                    $requiredSavings = $this->requiredSavingsForGuarantee($guaranteeAmount, $product);

                    $channels = array_values(array_filter([
                        $email ? 'email' : null,
                        $phone ? 'sms' : null,
                    ]));
                    if (! empty($input['channels']) && is_array($input['channels'])) {
                        $requestedChannels = array_values(array_intersect(
                            MemberInvitation::CHANNELS,
                            array_map('strval', $input['channels'])
                        ));
                        if ($requestedChannels !== []) {
                            $channels = $requestedChannels;
                        }
                    }

                    $note = sprintf(
                        '%s asked you to guarantee %s for loan %s. Join %s and deposit at least %s in qualifying savings to fund this guarantee.',
                        $member->full_name,
                        Money::format($guaranteeAmount),
                        $loan->loan_no,
                        $group->name,
                        Money::format($requiredSavings)
                    );

                    $invitation = $this->invitations->create($member, [
                        'email' => $email,
                        'phone' => $phone,
                        'channels' => $channels,
                        'note' => Str::limit($note, 500),
                    ]);

                    $status = Guarantor::STATUS_INVITED;
                    $invitationSentAt = now();

                    AuditService::log('guarantee.invited', [
                        'guarantee_loan_id' => $loan->id,
                        'invitation_id' => $invitation->id,
                        'email' => $email,
                        'phone' => $phone,
                        'required_savings_amount' => $requiredSavings,
                    ], $group);
                }

                $guarantee = Guarantor::withoutGlobalScopes()->create([
                    'group_id' => $member->group_id,
                    'loan_id' => $loan->id,
                    'guarantor_member_id' => $guarantorMember?->id,
                    'invitation_id' => $invitation?->id,
                    'invitee_email' => $email,
                    'invitee_phone' => $phone,
                    'guaranteed_amount' => $guaranteeAmount,
                    'required_savings_amount' => $requiredSavings,
                    'principal_amount' => $principalAmount,
                    'full_obligation_amount' => $fullObligationAmount,
                    'coverage_basis' => $guaranteeSettings->newGuarantees(),
                    'status' => $status,
                    'capacity_at_request' => $capacityAtRequest,
                    'requested_at' => $requestedAt,
                    'invitation_sent_at' => $invitationSentAt,
                ]);

                if ($guarantorMember) {
                    $portalUrl = route('portal.loans.index', absolute: true).'#guarantee-'.$guarantee->id;

                    $this->notifications->send(
                        $guarantorMember,
                        'guarantor_request',
                        [
                            'member_name' => $guarantorMember->full_name,
                            'borrower_name' => $member->full_name,
                            'amount' => Money::format($guaranteeAmount),
                            'loan_no' => $loan->loan_no,
                            'portal_url' => $portalUrl,
                        ],
                        $guaranteeSettings->notificationChannels()
                    );
                }

                $guarantorCount++;
            }

            $requiresGuarantorResponses = $guarantorCount > 0
                && ($guarantorMode === 'required' || $guarantorMode === 'optional');

            if ($requiresGuarantorResponses) {
                $loan->update(['status' => Loan::STATUS_GUARANTORS_PENDING]);
            } else {
                $loan->update(['status' => Loan::STATUS_UNDER_REVIEW]);
            }

            $this->notifications->send($member, 'loan_submitted', [
                'member_name' => $member->full_name,
                'loan_no' => $loan->loan_no,
                'amount' => Money::format($amount),
            ]);

            AuditService::log('loan.applied', [
                'loan_id' => $loan->id,
                'loan_no' => $loan->loan_no,
                'amount' => $amount,
            ], Group::find($member->group_id));

            return $loan->refresh();
        });
    }

    /**
     * A borrower who has reserved savings for another guarantee can only use
     * the unreserved remainder to cover their own loan. Every guarantor they
     * add contributes their share of the full repayment obligation.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $guarantorInputs
     */
    private function assertGuaranteeBackedBorrowing(
        Member $member,
        int $requestedPrincipal,
        int $fullObligation,
        int $remainingSavings,
        $guarantorInputs
    ): void {
        $defaultShare = (int) ceil($fullObligation / max(1, $guarantorInputs->count()));
        $coverage = $remainingSavings;

        foreach ($guarantorInputs as $input) {
            if ((int) ($input['member_id'] ?? 0) === $member->id) {
                // A self-guarantee is backed by the savings already counted.
                continue;
            }

            $amount = $input['amount'] ?? null;
            $coverage += $amount !== null && $amount !== ''
                ? (int) ceil($fullObligation * (int) $amount / max(1, $requestedPrincipal))
                : $defaultShare;
        }

        if ($coverage < $fullObligation) {
            throw new BusinessRuleException(
                'Your remaining savings of '.Money::format($remainingSavings).' cannot cover the full repayment of '
                .Money::format($fullObligation).'. Add guarantors to cover the shortfall of '
                .Money::format($fullObligation - $coverage).'.'
            );
        }
    }

    /**
     * Minimum qualifying savings needed to support a guarantee at the product's
     * configured guarantee multiplier.
     */
    private function requiredSavingsForGuarantee(int $guaranteeAmount, LoanProduct $product): int
    {
        $multiplier = (int) $product->guarantee_multiplier_bps;

        if ($multiplier <= 0) {
            throw new BusinessRuleException('This loan product does not support invited guarantors.');
        }

        return (int) ceil($guaranteeAmount * 10000 / $multiplier);
    }

    /**
     * An invited guarantor's guarantee becomes a real request once their
     * membership application has been approved. At that point they only need
     * to deposit the qualifying savings before the request is delivered.
     */
    public function activateInvitationGuarantees(Member $member, MemberInvitation $invitation): void
    {
        $guarantees = Guarantor::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('invitation_id', $invitation->id)
            ->where('status', Guarantor::STATUS_INVITED)
            ->with(['loan' => fn ($loan) => $loan->withoutGlobalScopes()->with([
                'member' => fn ($borrower) => $borrower->withoutGlobalScopes(),
                'product' => fn ($product) => $product->withoutGlobalScopes(),
            ])])
            ->get();

        if ($guarantees->isEmpty()) {
            return;
        }

        $group = Group::withoutGlobalScopes()->findOrFail($member->group_id);
        $settings = GuaranteeSettings::for($group);

        DB::transaction(function () use ($guarantees, $member, $group, $settings) {
            foreach ($guarantees as $guarantee) {
                $guarantee->update([
                    'guarantor_member_id' => $member->id,
                    'invitee_email' => $member->email ?: $guarantee->invitee_email,
                    'invitee_phone' => $member->phone ?: $guarantee->invitee_phone,
                    'status' => Guarantor::STATUS_AWAITING_SAVINGS,
                ]);

                $portalUrl = route('portal.loans.index', absolute: true)
                    .'#guarantee-savings-'.$guarantee->id;

                $this->notifications->send($member, 'guarantee_savings_required', [
                    'borrower_name' => $guarantee->loan?->member?->full_name ?? 'A group member',
                    'amount' => Money::format($guarantee->guaranteed_amount),
                    'savings_amount' => Money::format((int) $guarantee->required_savings_amount),
                    'loan_no' => $guarantee->loan?->loan_no ?? 'this loan',
                    'portal_url' => $portalUrl,
                ], $settings->notificationChannels());

                AuditService::log('guarantee.awaiting_savings', [
                    'guarantor_id' => $guarantee->id,
                    'loan_id' => $guarantee->loan_id,
                    'member_id' => $member->id,
                    'required_savings_amount' => $guarantee->required_savings_amount,
                ], $group);
            }
        });
    }

    /**
     * Resolve the invitation recorded on an approved application and activate
     * any guarantees that were created for that invitation.
     */
    public function activateInvitationGuaranteesForMember(Member $member): void
    {
        $invitationId = $member->metadata['invitation_id'] ?? null;

        if (! $invitationId) {
            return;
        }

        $invitation = MemberInvitation::withoutGlobalScopes()->find($invitationId);

        if ($invitation) {
            $this->activateInvitationGuarantees($member, $invitation);
        }
    }

    /**
     * Called after a savings deposit. Once the new member has enough eligible
     * savings, the guarantee becomes a normal pending request with portal link.
     */
    public function fundAwaitingGuarantees(Member $member): void
    {
        $guarantees = Guarantor::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('guarantor_member_id', $member->id)
            ->where('status', Guarantor::STATUS_AWAITING_SAVINGS)
            ->with(['loan' => fn ($loan) => $loan->withoutGlobalScopes()->with([
                'member' => fn ($borrower) => $borrower->withoutGlobalScopes(),
                'product' => fn ($product) => $product->withoutGlobalScopes(),
            ])])
            ->get();

        if ($guarantees->isEmpty()) {
            return;
        }

        $group = Group::withoutGlobalScopes()->findOrFail($member->group_id);
        $settings = GuaranteeSettings::for($group);

        DB::transaction(function () use ($guarantees, $member, $group, $settings) {
            foreach ($guarantees as $guarantee) {
                $product = $guarantee->loan?->product;

                if (! $product) {
                    continue;
                }

                $requiredAmount = $settings->commitmentAmount($guarantee);
                $capacity = $this->multiplier->guarantorCapacity($member, $product);

                if ($capacity['available'] < $requiredAmount) {
                    continue;
                }

                $guarantee->update([
                    'status' => Guarantor::STATUS_PENDING,
                    'capacity_at_request' => $capacity['available'],
                    'requested_at' => now(),
                    'funded_at' => now(),
                ]);

                $portalUrl = route('portal.loans.index', absolute: true).'#guarantee-'.$guarantee->id;

                $this->notifications->send($member, 'guarantor_request', [
                    'borrower_name' => $guarantee->loan?->member?->full_name ?? 'A group member',
                    'amount' => Money::format($guarantee->guaranteed_amount),
                    'loan_no' => $guarantee->loan?->loan_no ?? 'this loan',
                    'portal_url' => $portalUrl,
                ], $settings->notificationChannels());

                AuditService::log('guarantee.funded', [
                    'guarantor_id' => $guarantee->id,
                    'loan_id' => $guarantee->loan_id,
                    'member_id' => $member->id,
                    'capacity_at_request' => $capacity['available'],
                ], $group);
            }
        });
    }

    public function respondToGuarantee(
        Guarantor $guarantor,
        Member $actor,
        bool $accept,
        ?string $note = null
    ): Guarantor {
        if ($guarantor->guarantor_member_id !== $actor->id || $guarantor->group_id !== $actor->group_id) {
            throw new BusinessRuleException('You can only respond to your own guarantee requests.');
        }

        if ($guarantor->status !== Guarantor::STATUS_PENDING) {
            throw new BusinessRuleException('This guarantee request is no longer pending.');
        }

        return DB::transaction(function () use ($guarantor, $actor, $accept, $note) {
            $guarantor = Guarantor::withoutGlobalScopes()->lockForUpdate()->findOrFail($guarantor->id);
            $loan = Loan::withoutGlobalScopes()->findOrFail($guarantor->loan_id);
            $product = LoanProduct::withoutGlobalScopes()->findOrFail($loan->loan_product_id);
            $respondedAt = now();

            if ($accept) {
                $settings = GuaranteeSettings::for(
                    Group::withoutGlobalScopes()->find($guarantor->group_id)
                );
                $requiredAmount = $settings->commitmentAmount($guarantor);

                $capacity = $this->multiplier->guarantorCapacity($actor, $product);
                if ($capacity['available'] < $requiredAmount) {
                    throw new BusinessRuleException(
                        'Your available guarantee capacity ('.Money::format($capacity['available']).') is insufficient for '
                        .Money::format($requiredAmount).'.'
                    );
                }
            }

            $guarantor->update([
                'status' => $accept ? Guarantor::STATUS_ACCEPTED : Guarantor::STATUS_REJECTED,
                'responded_at' => $respondedAt,
                'response_note' => $note,
            ]);

            $allAccepted = ! $loan->guarantors()
                ->whereIn('status', Guarantor::UNFULFILLED_STATUSES)
                ->exists();

            if ($accept && $allAccepted && $loan->status === Loan::STATUS_GUARANTORS_PENDING) {
                $loan->update(['status' => Loan::STATUS_UNDER_REVIEW]);

                // The borrower is not the acting member here, so the member
                // scope on the loan relation would hide them.
                $borrower = Member::withoutGlobalScopes()->find($loan->member_id);

                if ($borrower) {
                    $this->notifications->send($borrower, 'loan_ready_for_review', [
                        'member_name' => $borrower->full_name,
                        'loan_no' => $loan->loan_no,
                    ]);
                }
            }

            AuditService::log('guarantee.responded', [
                'guarantor_id' => $guarantor->id,
                'loan_id' => $loan->id,
                'accepted' => $accept,
            ]);

            return $guarantor->refresh();
        });
    }

    /**
     * Capture the guarantor's e-signature as a separate step after they have
     * accepted the request. The EDMS document is the authoritative record and
     * the CRM/audit entries make the signing visible to staff.
     */
    public function signGuarantee(Guarantor $guarantor, Member $actor, string $signature): Document
    {
        if ($guarantor->guarantor_member_id !== $actor->id || $guarantor->group_id !== $actor->group_id) {
            throw new BusinessRuleException('You can only sign your own guarantee requests.');
        }

        if ($guarantor->status !== Guarantor::STATUS_ACCEPTED) {
            throw new BusinessRuleException('Only an accepted guarantee can be signed.');
        }

        return DB::transaction(function () use ($guarantor, $actor, $signature) {
            $guarantor = Guarantor::withoutGlobalScopes()->lockForUpdate()->findOrFail($guarantor->id);

            if ($guarantor->signatures()->exists()) {
                throw new BusinessRuleException('This guarantee has already been signed.');
            }

            SignatureCapture::requireValid($signature);

            $loan = Loan::withoutGlobalScopes()->findOrFail($guarantor->loan_id);
            $document = $this->signatures->store($guarantor, $actor, $loan, $signature);
            $signedAt = now();

            // CRM is a visibility log only. The EDMS document remains the
            // authoritative signature record.
            Communication::create([
                'group_id' => $guarantor->group_id,
                'member_id' => $actor->id,
                'channel' => 'in_app',
                'direction' => 'outbound',
                'subject' => 'Guarantee signed · '.$loan->loan_no,
                'body' => $actor->full_name.' signed guarantee #'.$guarantor->id.' for loan '.$loan->loan_no.'.',
                'status' => 'sent',
                'sent_at' => $signedAt,
                'metadata' => [
                    'guarantor_id' => $guarantor->id,
                    'loan_id' => $loan->id,
                    'document_id' => $document->id,
                    'signed_at' => $signedAt->toIso8601String(),
                ],
                'created_by' => $actor->user_id,
            ]);

            AuditService::log('guarantee.signed', [
                'guarantor_id' => $guarantor->id,
                'loan_id' => $loan->id,
                'document_id' => $document->id,
            ]);

            return $document;
        });
    }

    /**
     * @return array<int, array{role:string, name:string, max_amount?:int}>
     */
    public function approvalSteps(Loan $loan): array
    {
        $workflow = ApprovalWorkflow::withoutGlobalScopes()
            ->where('group_id', $loan->group_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('loan_product_id', $loan->loan_product_id)->orWhereNull('loan_product_id'))
            ->orderByRaw('loan_product_id IS NULL')
            ->first();

        if ($workflow && ! empty($workflow->steps)) {
            return $workflow->steps;
        }

        return [
            ['role' => 'loan_officer', 'name' => 'Loan Officer'],
            ['role' => 'treasurer', 'name' => 'Treasurer'],
            ['role' => 'credit_committee', 'name' => 'Credit Committee'],
            ['role' => 'chairperson', 'name' => 'Chairperson'],
        ];
    }

    public function currentStep(Loan $loan): ?array
    {
        $steps = $this->approvalSteps($loan);
        $approvedCount = $loan->approvals()
            ->whereIn('action', ['approved', 'conditional'])
            ->count();

        return $steps[$approvedCount] ?? null;
    }

    public function approve(Loan $loan, User $actor, string $action, ?string $comments = null): Loan
    {
        if (! in_array($action, ['approved', 'rejected', 'info_requested', 'conditional'], true)) {
            throw new BusinessRuleException('Invalid approval action.');
        }

        if (! in_array($loan->status, [Loan::STATUS_UNDER_REVIEW, Loan::STATUS_GUARANTORS_PENDING], true)) {
            throw new BusinessRuleException('This loan is not awaiting approval.');
        }

        if ($action !== 'info_requested'
            && $loan->guarantors()->whereIn('status', Guarantor::UNFULFILLED_STATUSES)->exists()) {
            throw new BusinessRuleException('All guarantors must respond before approval.');
        }

        if ($loan->member->user_id === $actor->id) {
            throw new BusinessRuleException('You cannot approve your own loan.');
        }

        if ($loan->approvals()->where('actor_id', $actor->id)->whereIn('action', ['approved', 'conditional'])->exists()) {
            throw new BusinessRuleException('You have already acted on this loan. A different official must complete the next step.');
        }

        return DB::transaction(function () use ($loan, $actor, $action, $comments) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);
            $steps = $this->approvalSteps($loan);
            $stepIndex = $loan->approvals()->whereIn('action', ['approved', 'conditional'])->count();
            $step = $steps[$stepIndex] ?? null;

            if (! $step) {
                throw new BusinessRuleException('No approval step remains for this loan.');
            }

            $membership = $actor->memberships()
                ->where('group_id', $loan->group_id)
                ->where('status', 'active')
                ->with('role')
                ->first();

            if (! $membership) {
                throw new BusinessRuleException('You do not have an active role in this group.');
            }

            $roleKey = $membership->role?->key;
            $isGroupAdmin = $roleKey === 'group_admin';

            if (! $isGroupAdmin && $roleKey !== $step['role']) {
                throw new BusinessRuleException("This step requires the {$step['role']} role.");
            }

            if (! $isGroupAdmin && ! $this->membershipCanApproveAmount($membership, (int) $loan->requested_amount)) {
                throw new BusinessRuleException('The requested amount exceeds your approval limit.');
            }

            if (isset($step['max_amount']) && $loan->requested_amount > $step['max_amount'] && ! $isGroupAdmin) {
                throw new BusinessRuleException('This loan exceeds the limit for this approval step.');
            }

            LoanApproval::withoutGlobalScopes()->create([
                'group_id' => $loan->group_id,
                'loan_id' => $loan->id,
                'step_index' => $stepIndex + 1,
                'role_key' => $step['role'],
                'actor_id' => $actor->id,
                'action' => $action,
                'comments' => $comments,
                'acted_at' => now(),
            ]);

            if ($action === 'rejected') {
                $loan->update([
                    'status' => Loan::STATUS_REJECTED,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);

                $this->notifications->send($loan->member, 'loan_rejected', [
                    'member_name' => $loan->member->full_name,
                    'loan_no' => $loan->loan_no,
                    'comments' => $comments ?? '',
                ]);
            } elseif ($action === 'info_requested') {
                $loan->update(['status' => Loan::STATUS_UNDER_REVIEW]);
                $this->notifications->send($loan->member, 'loan_info_requested', [
                    'member_name' => $loan->member->full_name,
                    'loan_no' => $loan->loan_no,
                    'comments' => $comments ?? '',
                ]);
            } else {
                $isFinal = ($stepIndex + 1) >= count($steps);

                if ($isFinal) {
                    $loan->update([
                        'status' => Loan::STATUS_APPROVED,
                        'approved_amount' => $loan->requested_amount,
                        'approved_by' => $actor->id,
                        'approved_at' => now(),
                    ]);

                    $this->generateSchedule($loan, $loan->requested_amount, true);

                    $this->notifications->send($loan->member, 'loan_approved', [
                        'member_name' => $loan->member->full_name,
                        'loan_no' => $loan->loan_no,
                        'amount' => Money::format($loan->approved_amount),
                    ]);
                } else {
                    $loan->update(['status' => Loan::STATUS_UNDER_REVIEW]);
                    $this->notifications->send($loan->member, 'loan_approval_progress', [
                        'member_name' => $loan->member->full_name,
                        'loan_no' => $loan->loan_no,
                        'step' => $step['name'] ?? $step['role'],
                    ]);
                }
            }

            AuditService::log('loan.'.$action, [
                'loan_id' => $loan->id,
                'step' => $stepIndex + 1,
                'comments' => $comments,
            ], Group::find($loan->group_id));

            return $loan->refresh();
        });
    }

    public function disburse(Loan $loan, User $actor, string $method = 'cash', ?string $reference = null): Loan
    {
        if ($loan->status !== Loan::STATUS_APPROVED) {
            throw new BusinessRuleException('Only an approved loan can be disbursed.');
        }

        if ($loan->requiresEmployerFinance()
            && $loan->employer_finance_status !== EmployerFinance::STATUS_CONFIRMED) {
            throw new BusinessRuleException(
                'The employer finance office must confirm the stamped form before this loan can be disbursed.'
            );
        }

        return DB::transaction(function () use ($loan, $actor, $method, $reference) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);
            $group = Group::findOrFail($loan->group_id);
            $start = CarbonImmutable::now()->startOfDay();

            // Refresh the schedule from the actual disbursement date.
            $this->generateSchedule($loan, $loan->approved_amount, true, $start);

            $loan->refresh();
            $counterCode = $method === 'bank' ? LedgerService::BANK : LedgerService::CASH;

            $journal = $this->ledger->post($group, 'Loan disbursement '.$loan->loan_no, [
                ['code' => LedgerService::LOANS_RECEIVABLE, 'debit' => $loan->principal, 'member_id' => $loan->member_id],
                ['code' => $counterCode, 'credit' => $loan->principal, 'member_id' => $loan->member_id],
            ], now(), $loan, $reference);

            $principal = (int) $loan->approved_amount;

            $loan->update([
                'status' => Loan::STATUS_ACTIVE,
                'principal' => $principal,
                'outstanding_principal' => $principal,
                'outstanding_interest' => $loan->total_interest,
                'outstanding_fees' => $loan->total_fees,
                'outstanding_penalties' => 0,
                'amount_paid' => 0,
                'disbursed_at' => now(),
                'disbursed_by' => $actor->id,
                'first_due_date' => $loan->schedules()->min('due_date'),
                'maturity_date' => $loan->schedules()->max('due_date'),
                'metadata' => array_merge($loan->metadata ?? [], [
                    'disbursement_journal_id' => $journal->id,
                    'disbursement_method' => $method,
                ]),
            ]);

            $this->notifications->send($loan->member, 'loan_disbursed', [
                'member_name' => $loan->member->full_name,
                'loan_no' => $loan->loan_no,
                'amount' => Money::format($loan->principal),
                'first_due_date' => optional($loan->first_due_date)->format('d M Y'),
            ]);

            AuditService::log('loan.disbursed', [
                'loan_id' => $loan->id,
                'amount' => $loan->principal,
                'method' => $method,
            ], $group);

            return $loan->refresh();
        });
    }

    public function generateSchedule(Loan $loan, int $principal, bool $replace = false, ?CarbonImmutable $start = null): Loan
    {
        $product = LoanProduct::withoutGlobalScopes()->findOrFail($loan->loan_product_id);
        $start ??= CarbonImmutable::parse($loan->approved_at ?? now())->startOfDay();
        $fee = $this->feeTotal($product, $principal);

        $quote = $this->calculator->quote($this->calculatorTerms(
            $product,
            $principal,
            $loan->duration,
            $loan->duration_unit,
            $start,
            $fee
        ));

        DB::transaction(function () use ($loan, $quote, $principal, $fee, $replace) {
            $loan = Loan::withoutGlobalScopes()->lockForUpdate()->findOrFail($loan->id);

            if ($replace) {
                $hasPayments = $loan->schedules()->where('principal_paid', '>', 0)
                    ->orWhere('interest_paid', '>', 0)
                    ->exists();

                if ($hasPayments) {
                    throw new BusinessRuleException('A loan with recorded repayments cannot have its schedule regenerated.');
                }

                LoanSchedule::withoutGlobalScopes()->where('loan_id', $loan->id)->delete();
            }

            foreach ($quote->lines as $line) {
                LoanSchedule::withoutGlobalScopes()->create([
                    'group_id' => $loan->group_id,
                    'loan_id' => $loan->id,
                    'installment_no' => $line->installmentNo,
                    'due_date' => $line->dueDate->toDateString(),
                    'principal_due' => $line->principalDue,
                    'interest_due' => $line->interestDue,
                    'fee_due' => $line->feeDue,
                    'penalty_due' => 0,
                    'total_due' => $line->totalDue,
                    'principal_paid' => 0,
                    'interest_paid' => 0,
                    'fee_paid' => 0,
                    'penalty_paid' => 0,
                    'status' => 'pending',
                ]);
            }

            $loan->update([
                'principal' => $principal,
                'approved_amount' => $principal,
                'total_interest' => $quote->totalInterest,
                'total_fees' => $fee,
                'total_repayable' => $quote->totalRepayable,
                'installment_count' => $quote->installmentCount,
                'first_due_date' => $quote->firstDueDate->toDateString(),
                'maturity_date' => $quote->maturityDate->toDateString(),
                'terms_snapshot' => array_merge($loan->terms_snapshot ?? [], [
                    'quotation' => $quote->toArray(),
                    'approved_amount' => $principal,
                ]),
            ]);
        });

        return $loan->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function calculatorTerms(
        LoanProduct $product,
        int $amount,
        int $duration,
        string $durationUnit,
        CarbonImmutable $start,
        int $fees
    ): array {
        return [
            'principal' => $amount,
            'rate_bps' => $product->interest_rate_bps,
            'interest_period' => $product->interest_period,
            'method' => $product->interest_method,
            'repayment_frequency' => $product->repayment_frequency,
            'duration' => $duration,
            'duration_unit' => $durationUnit,
            'day_count' => $product->day_count,
            'accrual_mode' => $product->accrual_mode,
            'start_date' => $start,
            'fee_amount' => $fees,
            'repayment_day' => $product->repayment_day,
        ];
    }

    private function feeTotal(LoanProduct $product, int $amount): int
    {
        return $product->processingFeeFor($amount) + $product->insuranceFeeFor($amount);
    }

    private function assertDuration(LoanProduct $product, int $duration, string $unit): void
    {
        if ($unit !== $product->duration_unit) {
            // Normalise to the product unit for limit checks.
            $months = match ($unit) {
                'days' => $duration / 30.44,
                'weeks' => $duration / 4.345,
                'months' => $duration,
                default => throw new BusinessRuleException('Invalid duration unit.'),
            };
            $min = match ($product->duration_unit) {
                'days' => $product->duration_min,
                'weeks' => $product->duration_min * 7,
                'months' => $product->duration_min * 30.44,
                default => $product->duration_min,
            };
            $max = match ($product->duration_unit) {
                'days' => $product->duration_max,
                'weeks' => $product->duration_max * 7,
                'months' => $product->duration_max * 30.44,
                default => $product->duration_max,
            };
            $days = $months * 30.44;
        } else {
            $days = match ($unit) {
                'days' => $duration,
                'weeks' => $duration * 7,
                'months' => $duration * 30.44,
                default => 0,
            };
            $min = match ($product->duration_unit) {
                'days' => $product->duration_min,
                'weeks' => $product->duration_min * 7,
                'months' => $product->duration_min * 30.44,
                default => $product->duration_min,
            };
            $max = match ($product->duration_unit) {
                'days' => $product->duration_max,
                'weeks' => $product->duration_max * 7,
                'months' => $product->duration_max * 30.44,
                default => $product->duration_max,
            };
        }

        if ($days < $min || $days > $max + 0.5) {
            throw new BusinessRuleException(
                'Duration must be between '.$product->duration_min.' and '.$product->duration_max.' '.$product->duration_unit.'.'
            );
        }
    }

    private function membershipCanApproveAmount(Member $membership, int $amount): bool
    {
        $limit = $membership->role?->approval_limit;

        return $limit === null || $amount <= $limit;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeAdvanceApplication(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $allowed = [
            'kra_pin', 'payroll_number', 'department', 'job_title', 'employment_terms',
            'contract_expiry_date', 'gross_monthly_salary', 'net_monthly_salary',
            'disbursement_method', 'disbursement_bank', 'disbursement_branch',
            'disbursement_account_name', 'disbursement_account_number',
        ];

        $data = [];

        foreach ($allowed as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $data[$key] = $value;
        }

        return $data === [] ? null : $data;
    }
}
