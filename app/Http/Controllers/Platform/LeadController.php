<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MarketingLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadController extends Controller
{
    public function index(): View
    {
        return view('platform.leads.index', [
            'leads' => MarketingLead::query()->latest()->paginate(20),
            'statuses' => [
                MarketingLead::STATUS_NEW,
                MarketingLead::STATUS_CONTACTED,
                MarketingLead::STATUS_QUALIFIED,
                MarketingLead::STATUS_CONVERTED,
                MarketingLead::STATUS_LOST,
            ],
        ]);
    }

    public function update(Request $request, MarketingLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                MarketingLead::STATUS_NEW,
                MarketingLead::STATUS_CONTACTED,
                MarketingLead::STATUS_QUALIFIED,
                MarketingLead::STATUS_CONVERTED,
                MarketingLead::STATUS_LOST,
            ])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $lead->update([
            'status' => $data['status'],
            'notes' => ($data['notes'] ?? null) ?: null,
        ]);

        return back()->with('status', 'Access request updated.');
    }
}
