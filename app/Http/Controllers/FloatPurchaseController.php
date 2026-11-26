<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\FloatPurchase;
use App\Services\FloatPurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FloatPurchaseController extends Controller
{
    public function __construct(private readonly FloatPurchaseService $floats)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('float_purchases.view');

        $status = (string) $request->string('status', '');
        $query = FloatPurchase::query()->with(['member', 'requester']);

        if ($status !== '') {
            $query->where('status', $status);
        }

        return view('float-purchases.index', [
            'purchases' => $query->orderByDesc('created_at')->paginate(25)->withQueryString(),
            'statuses' => FloatPurchase::STATUSES,
            'status' => $status,
        ]);
    }

    public function fulfil(Request $request, FloatPurchase $purchase): RedirectResponse
    {
        $this->requirePermission('float_purchases.manage');

        $data = $request->validate([
            'provider_reference' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $this->floats->complete($purchase, $this->actor(), $data['provider_reference'] ?: null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Float purchase marked as delivered. The member has been notified.');
    }

    public function fail(Request $request, FloatPurchase $purchase): RedirectResponse
    {
        $this->requirePermission('float_purchases.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->floats->fail($purchase, $this->actor(), $data['reason']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Float purchase failed and the wallet was refunded.');
    }

    public function refresh(FloatPurchase $purchase): RedirectResponse
    {
        $this->requirePermission('float_purchases.manage');

        try {
            $this->floats->refresh($purchase);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Provider status refreshed.');
    }
}
