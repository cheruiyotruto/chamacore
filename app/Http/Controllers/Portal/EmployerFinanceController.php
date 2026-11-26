<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\EmployerFinanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployerFinanceController extends Controller
{
    public function form(Loan $loan, EmployerFinanceService $service): Response
    {
        $this->authorize('view', $loan);
        $this->assertOwnedByMember($loan);

        if (! $loan->requiresEmployerFinance()) {
            abort(404, 'This loan does not use the employer finance workflow.');
        }

        $service->recordFormDownloaded($loan);

        $loan->load(['member', 'product']);
        $settings = $service->settingsFor($loan);

        $pdf = Pdf::loadView('pdf.employer-finance-form', [
            'loan' => $loan,
            'settings' => $settings,
        ])->setPaper('a4');

        return $pdf->download('employer-finance-form-'.$loan->loan_no.'.pdf');
    }

    public function upload(Request $request, Loan $loan, EmployerFinanceService $service): RedirectResponse
    {
        $this->authorize('view', $loan);
        $this->assertOwnedByMember($loan);

        $data = $request->validate([
            'stamped_form' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'consent' => ['accepted'],
        ]);

        try {
            $service->storeStampedForm($loan, $data['stamped_form'], $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with(
            'status',
            'Stamped form uploaded. The finance office has been contacted for confirmation.'
        );
    }

    private function assertOwnedByMember(Loan $loan): void
    {
        abort_unless($loan->member_id === $this->membership()->id, 403);
    }
}
