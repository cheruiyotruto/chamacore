<?php

namespace App\Http\Controllers;

use App\Models\GroupPolicy;
use App\Models\Meeting;
use App\Models\Resolution;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GovernanceController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('governance.view');

        $policies = GroupPolicy::query()
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->orderByDesc('effective_date')
            ->paginate(15, ['*'], 'policies')
            ->withQueryString();

        $resolutions = Resolution::query()
            ->withCount('votes')
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'resolutions')
            ->withQueryString();

        return view('governance.index', [
            'policies' => $policies,
            'resolutions' => $resolutions,
            'meetings' => Meeting::orderByDesc('scheduled_at')->limit(20)->get(),
            'policyTypes' => ['constitution', 'policy', 'bylaw', 'code_of_conduct'],
            'canManage' => $this->context()->hasPermission('governance.manage'),
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $this->requirePermission('governance.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:constitution,policy,bylaw,code_of_conduct'],
            'content' => ['required', 'string', 'max:50000'],
            'version' => ['required', 'string', 'max:20'],
            'effective_date' => ['required', 'date'],
            'status' => ['required', 'in:draft,active,archived'],
        ]);

        $policy = GroupPolicy::create([
            'group_id' => $this->group()->id,
            ...$data,
            'approved_by' => $data['status'] === 'active' ? $this->actor()->id : null,
            'approved_at' => $data['status'] === 'active' ? now() : null,
        ]);

        AuditService::record('policy.created', $policy, null, $policy->only(['title', 'type', 'version', 'status']));

        return back()->with('status', 'Policy saved.');
    }

    public function updatePolicy(Request $request, GroupPolicy $policy): RedirectResponse
    {
        $this->requirePermission('governance.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:constitution,policy,bylaw,code_of_conduct'],
            'content' => ['required', 'string', 'max:50000'],
            'version' => ['required', 'string', 'max:20'],
            'effective_date' => ['required', 'date'],
            'status' => ['required', 'in:draft,active,archived'],
        ]);

        $old = $policy->only(['title', 'type', 'version', 'status']);
        $policy->update([
            ...$data,
            'approved_by' => $data['status'] === 'active' ? ($policy->approved_by ?? $this->actor()->id) : $policy->approved_by,
            'approved_at' => $data['status'] === 'active' ? ($policy->approved_at ?? now()) : $policy->approved_at,
        ]);

        AuditService::record('policy.updated', $policy, $old, $policy->only(['title', 'type', 'version', 'status']));

        return back()->with('status', 'Policy updated.');
    }

    public function storeResolution(Request $request): RedirectResponse
    {
        $this->requirePermission('governance.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:5000'],
            'meeting_id' => ['nullable', 'integer'],
            'voting_opens_at' => ['nullable', 'date'],
            'voting_closes_at' => ['nullable', 'date', 'after:voting_opens_at'],
        ]);

        $resolution = Resolution::create([
            'group_id' => $this->group()->id,
            'meeting_id' => $data['meeting_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => 'open',
            'voting_opens_at' => $data['voting_opens_at'] ?? now(),
            'voting_closes_at' => $data['voting_closes_at'] ?? null,
            'created_by' => $this->actor()->id,
        ]);

        AuditService::record('resolution.created', $resolution, null, $resolution->only(['title', 'status']));

        return back()->with('status', 'Resolution opened for voting.');
    }

    public function closeResolution(Resolution $resolution): RedirectResponse
    {
        $this->requirePermission('governance.manage');

        abort_unless($resolution->group_id === $this->group()->id, 403);

        if ($resolution->status !== 'open') {
            return back()->with('error', 'Only open resolutions can be closed.');
        }

        $for = $resolution->votes()->where('vote', 'for')->count();
        $against = $resolution->votes()->where('vote', 'against')->count();
        $abstain = $resolution->votes()->where('vote', 'abstain')->count();
        $passed = $for > $against;

        $resolution->update([
            'votes_for' => $for,
            'votes_against' => $against,
            'votes_abstain' => $abstain,
            'status' => $passed ? 'passed' : 'rejected',
            'passed_at' => $passed ? now() : null,
            'voting_closes_at' => $resolution->voting_closes_at ?? now(),
        ]);

        AuditService::record('resolution.closed', $resolution, null, [
            'votes_for' => $for,
            'votes_against' => $against,
            'status' => $resolution->status,
        ]);

        return back()->with('status', 'Resolution closed and recorded as '.$resolution->status.'.');
    }
}
