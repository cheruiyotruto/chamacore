<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentChargeController extends Controller
{
    public function index(): View
    {
        $rules = PaymentChargeSettings::platform();

        return view('platform.charges.index', [
            'rules' => $rules,
            'categories' => PaymentChargeSettings::CATEGORIES,
            'modes' => PaymentChargeSettings::MODES,
            'borneBy' => PaymentChargeSettings::BORNE_BY,
            'incomeAccounts' => app(LedgerService::class)->chartIncomeAccounts(),
            'money' => fn (?int $minor) => $minor === null ? '' : number_format($minor / 100, 2, '.', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = PaymentChargeSettings::fromRequest(
            $request,
            array_keys(app(LedgerService::class)->chartIncomeAccounts())
        );

        PlatformSetting::updateOrCreate(
            ['key' => PaymentChargeSettings::KEY],
            ['value' => $rules, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('platform.payment_charges.updated', ['categories' => array_keys($rules)]);

        return back()->with('status', 'Platform payment charge defaults saved.');
    }
}
