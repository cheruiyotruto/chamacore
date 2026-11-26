<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\GroupPolicy;
use App\Models\Resolution;
use App\Models\Vote;
use App\Services\AuditService;
use App\Support\MemberScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GovernanceController extends Controller
{
    public function index(): View
    {
        $this->requirePermission('portal.view');

        $member = $this->membership();

        $policies = GroupPolicy::query()
            ->where('status', 'active')
            ->orderByDesc('effective_date')
            ->paginate(10, ['*'], 'policies');

        $resolutions = Resolution::query()
            ->whereIn('status', ['open', 'passed', 'rejected'])
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'resolutions');

        $myVotes = Vote::query()
            ->where('member_id', $member->id)
            ->whereIn('resolution_id', $resolutions->pluck('id'))
            ->get()
            ->keyBy('resolution_id');

        return view('portal.governance.index', [
            'policies' => $policies,
            'resolutions' => $resolutions,
            'myVotes' => $myVotes,
        ]);
    }

    public function vote(Request $request, Resolution $resolution): RedirectResponse
    {
        $this->requirePermission('portal.view');

        $data = $request->validate([
            'vote' => ['required', 'in:for,against,abstain'],
        ]);

        $member = $this->membership();

        if ($resolution->status !== 'open') {
            return back()->with('error', 'Voting is closed for this resolution.');
        }

        if ($resolution->voting_opens_at && $resolution->voting_opens_at->isFuture()) {
            return back()->with('error', 'Voting for this resolution has not opened yet.');
        }

        if ($resolution->voting_closes_at && $resolution->voting_closes_at->isPast()) {
            return back()->with('error', 'Voting for this resolution has closed.');
        }

        $exists = Vote::query()
            ->where('resolution_id', $resolution->id)
            ->where('member_id', $member->id)
            ->exists();

        if ($exists) {
            return back()->with('error', 'You have already voted on this resolution.');
        }

        Vote::create([
            'group_id' => $this->group()->id,
            'resolution_id' => $resolution->id,
            'member_id' => $member->id,
            'vote' => $data['vote'],
            'voted_at' => now(),
        ]);

        // Recount across every member; the MemberScope is bypassed so the
        // aggregate is not limited to the authenticated member's own vote.
        $counts = Vote::query()
            ->withoutGlobalScope(MemberScope::class)
            ->where('resolution_id', $resolution->id)
            ->selectRaw("sum(case when vote = 'for' then 1 else 0 end) as votes_for")
            ->selectRaw("sum(case when vote = 'against' then 1 else 0 end) as votes_against")
            ->selectRaw("sum(case when vote = 'abstain' then 1 else 0 end) as votes_abstain")
            ->first();

        $resolution->update([
            'votes_for' => (int) ($counts->votes_for ?? 0),
            'votes_against' => (int) ($counts->votes_against ?? 0),
            'votes_abstain' => (int) ($counts->votes_abstain ?? 0),
        ]);

        AuditService::record('resolution.vote', $resolution, null, [
            'vote' => $data['vote'],
            'member_id' => $member->id,
        ]);

        return back()->with('status', 'Your vote has been recorded.');
    }
}
