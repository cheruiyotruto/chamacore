<?php

namespace App\Http\Controllers;

use App\Models\GroupSetting;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Support\PaymentChargeSettings;
use App\Support\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentChargeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $group = $this->group();

        abort_if(
            PlanAccess::isPlatformManaged($group),
            403,
            'Payment charges are set by the platform administrator on this plan.'
        );

        $rules = PaymentChargeSettings::fromRequest(
            $request,
            array_keys(app(LedgerService::class)->incomeAccounts($group))
        );

        GroupSetting::updateOrCreate(
            ['group_id' => $group->id, 'key' => PaymentChargeSettings::KEY],
            ['value' => $rules, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('group.payment_charges.updated', ['categories' => array_keys($rules)], $group);

        return back()->with('status', 'Payment charge settings saved for this group.');
    }
}
