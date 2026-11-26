<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Role;
use App\Services\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branches)
    {
    }

    public function index(): View
    {
        $this->requirePermission('settings.view');

        $current = $this->group();
        $visibleIds = $current->visibleGroupIds();

        $branches = Group::withoutGlobalScopes()
            ->whereIn('id', $visibleIds)
            ->where('groups.id', '!=', $current->id)
            ->withCount(['members', 'activeMembers'])
            ->with('parent:id,name,branch_code')
            ->orderBy('name')
            ->get()
            ->map(function (Group $branch) use ($current) {
                $branch->can_manage = $current->id === $branch->parent_group_id
                    || $current->isHeadOffice();

                return $branch;
            });

        return view('branches.index', [
            'current' => $current,
            'organization' => $current->root(),
            'branches' => $branches,
            'branchCount' => Group::withoutGlobalScopes()->whereIn('id', $visibleIds)->where('is_branch', true)->count(),
            'totalMembers' => (int) DB::table('members')
                ->whereIn('group_id', $visibleIds)
                ->whereNull('deleted_at')
                ->count(),
            'managerRoles' => Role::withoutGlobalScopes()
                ->where('group_id', $current->id)
                ->where('key', '!=', 'member')
                ->orderBy('name')
                ->pluck('name', 'key'),
            'membershipGroupIds' => $this->actor()->memberships()->pluck('group_id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'branch_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'county' => ['nullable', 'string', 'max:100'],
            'sub_county' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_name' => ['nullable', 'string', 'max:150'],
            'manager_email' => ['nullable', 'email', 'max:190'],
            'manager_phone' => ['nullable', 'string', 'max:30'],
            'manager_role' => ['nullable', 'string', 'max:50'],
        ]);

        if (! empty($data['manager_email'])) {
            $data['manager_name'] = $data['manager_name'] ?? 'Branch Manager';
        }

        try {
            $branch = $this->branches->create($this->group(), $data, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('branches.index')
            ->with('status', 'Branch '.$branch->branch_code.' created. Switch groups to work inside it.');
    }

    public function update(Request $request, Group $branch): RedirectResponse
    {
        $this->requirePermission('settings.manage');
        $this->assertManageable($branch);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'branch_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'county' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:active,suspended'],
        ]);

        try {
            $this->branches->update($branch, $data, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Branch updated.');
    }

    public function toggleStatus(Group $branch): RedirectResponse
    {
        $this->requirePermission('settings.manage');
        $this->assertManageable($branch);

        abort_if($branch->id === $this->group()->id, 403, 'You cannot suspend the group you are currently using.');

        $this->branches->toggleStatus($branch, $this->actor());

        return back()->with('status', 'Branch status updated.');
    }

    private function assertManageable(Group $branch): void
    {
        $visibleIds = $this->group()->visibleGroupIds();

        abort_unless($branch->isBranch() && in_array($branch->id, $visibleIds, true), 404);

        if (! $this->group()->isHeadOffice()) {
            abort_unless($branch->parent_group_id === $this->group()->id, 403);
        }
    }
}
