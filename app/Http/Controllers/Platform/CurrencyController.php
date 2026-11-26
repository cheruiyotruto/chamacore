<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\CurrencySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(): View
    {
        return view('platform.currencies.index', [
            'catalogue' => CurrencySettings::catalogue(),
            'enabled' => CurrencySettings::enabledCodes(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currencies' => ['required', 'array', 'min:1'],
            'currencies.*' => ['required', 'string', Rule::in(array_keys(CurrencySettings::catalogue()))],
        ], [
            'currencies.required' => 'Enable at least one currency.',
            'currencies.min' => 'Enable at least one currency.',
        ]);

        $codes = CurrencySettings::known($data['currencies']);

        abort_if($codes === [], 422, 'Enable at least one known currency.');

        CurrencySettings::save($codes, $this->actor()->id);

        AuditService::log('platform.currencies.updated', ['currencies' => $codes]);

        return back()->with('status', 'Enabled currencies saved.');
    }
}
