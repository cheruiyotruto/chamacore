<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Loan;
use App\Services\EmployerFinanceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public, signature-protected confirmation page used by an employer's finance
 * office. Finance officers do not have platform accounts, so the signed URL is
 * the credential that allows them to confirm or decline a stamped form.
 */
class EmployerFinanceConfirmationController extends Controller
{
    public function show(Request $request, string $loan, EmployerFinanceService $service): View
    {
        $loan = $this->loan($loan);
        $this->assertCurrentLink($request, $loan, $service);

        return view('employer-finance.confirm', [
            'loan' => $loan->load(['member', 'product', 'documents']),
            'settings' => $service->settingsFor($loan),
            'submitUrl' => $service->confirmationStoreUrl($loan),
            'result' => null,
        ]);
    }

    public function store(Request $request, string $loan, EmployerFinanceService $service): View
    {
        $loan = $this->loan($loan);
        $this->assertCurrentLink($request, $loan, $service);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,decline'],
            'officer_name' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $approved = $data['decision'] === 'approve';

        try {
            $service->confirm(
                $loan,
                $approved,
                $data['officer_name'],
                $data['reference'] ?? null,
                $data['notes'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return view('employer-finance.confirm', [
                'loan' => $loan->load(['member', 'product', 'documents']),
                'settings' => $service->settingsFor($loan),
                'submitUrl' => $service->confirmationStoreUrl($loan),
                'result' => null,
                'error' => $e->getMessage(),
            ]);
        }

        return view('employer-finance.confirm', [
            'loan' => $loan->fresh(['member', 'product', 'documents']),
            'settings' => $service->settingsFor($loan),
            'submitUrl' => null,
            'result' => $approved ? 'approved' : 'declined',
        ]);
    }

    private function assertCurrentLink(Request $request, Loan $loan, EmployerFinanceService $service): void
    {
        abort_unless(
            $service->signedStateMatches($loan, $request->query('state'), $request->query('v')),
            403,
            'This confirmation link is no longer valid.'
        );
    }

    private function loan(string $id): Loan
    {
        return Loan::withoutGlobalScopes()
            ->with(['member', 'product'])
            ->findOrFail((int) $id);
    }
}
