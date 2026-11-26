<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Member;
use App\Services\MemberApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function index(): View
    {
        $members = Member::withoutGlobalScopes()
            ->withTrashed()
            ->whereIn('group_id', $this->platformGroupIds())
            ->whereIn('status', ['applied', 'approved'])
            ->with(['group:id,name,slug,public_join_enabled', 'role:id,name', 'user:id,name,email'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('platform.applications.index', [
            'members' => $members,
        ]);
    }

    public function approve(
        Request $request,
        int $member,
        MemberApplicationService $applications
    ): RedirectResponse {
        $application = $this->findApplication($member);

        $request->validate([
            'override_documents' => ['nullable', 'boolean'],
        ]);

        $applications->approve($application, $this->actor(), $request->boolean('override_documents'));

        return back()->with('status', $application->full_name.' approved and added to '.$application->group->name.'.');
    }

    public function reject(
        Request $request,
        int $member,
        MemberApplicationService $applications
    ): RedirectResponse {
        $application = $this->findApplication($member);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $applications->reject($application, $this->actor(), $data['reason']);

        return back()->with('status', $application->full_name.'\'s application was rejected.');
    }

    private function findApplication(int $id): Member
    {
        $member = Member::withoutGlobalScopes()
            ->withTrashed()
            ->with('group')
            ->findOrFail($id);

        abort_unless(
            in_array($member->status, ['applied', 'approved'], true)
                && $member->group
                && $member->group->isPlatformManaged(),
            404,
            'This application is not available for platform review.'
        );

        return $member;
    }

    /**
     * @return array<int, int>
     */
    private function platformGroupIds(): array
    {
        return Group::withoutGlobalScopes()
            ->get()
            ->filter(fn (Group $group) => $group->isPlatformManaged())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
