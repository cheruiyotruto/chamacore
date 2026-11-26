<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\MerryGoRoundCycle;
use App\Models\Wallet;
use App\Models\WelfareClaim;
use App\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if ($this->context()->isMember()) {
            return redirect()->route('portal.dashboard');
        }

        $group = $this->group();

        $activeMembers = Member::where('status', 'active')->count();
        $pendingMembers = Member::whereIn('status', ['applied', 'approved'])->count();
        $walletTotal = (int) Wallet::sum('balance');

        $mgrCycle = MerryGoRoundCycle::where('status', 'active')
            ->latest('start_date')
            ->first();

        $nextMeeting = Meeting::where('scheduled_at', '>=', now())
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->first();

        $pendingFines = (int) Fine::whereIn('status', ['pending', 'partially_paid'])->sum('amount');
        $pendingClaims = WelfareClaim::where('status', 'pending')->count();

        $ledger = app(LedgerService::class);
        $cashPosition = $ledger->accountBalance($group, LedgerService::CASH)
            + $ledger->accountBalance($group, LedgerService::BANK);

        return view('dashboard', compact(
            'group',
            'activeMembers',
            'pendingMembers',
            'walletTotal',
            'mgrCycle',
            'nextMeeting',
            'pendingFines',
            'pendingClaims',
            'cashPosition',
        ));
    }
}
