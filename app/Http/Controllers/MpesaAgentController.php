<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MpesaAgent;
use App\Services\AuditService;
use App\Support\KenyanPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff registry of M-Pesa agents and the stores they use to hold float.
 */
class MpesaAgentController extends Controller
{
    public function index(): View
    {
        $this->requirePermission('float_purchases.view');

        return view('mpesa-agents.index', [
            'agents' => MpesaAgent::withoutGlobalScopes()
                ->with('member')
                ->where('group_id', $this->group()->id)
                ->withCount('purchases')
                ->orderBy('name')
                ->get(),
            'members' => Member::active()->orderBy('first_name')->get(),
            'canManage' => $this->context()->hasPermission('float_purchases.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('float_purchases.manage');

        $data = $this->validateAgent($request);
        $phone = KenyanPhone::normalise($data['phone']);

        if ($phone === null) {
            return back()->withInput()->with('error', 'Enter a valid Kenyan phone number for the agent.');
        }

        MpesaAgent::withoutGlobalScopes()->create([
            ...$data,
            'group_id' => $this->group()->id,
            'phone' => $phone,
            'status' => MpesaAgent::STATUS_ACTIVE,
            'created_by' => $this->actor()->id,
        ]);

        AuditService::log('mpesa_agent.created', [
            'agent_number' => $data['agent_number'],
            'store_number' => $data['store_number'],
        ], $this->group());

        return back()->with('status', 'M-Pesa agent and store registered.');
    }

    public function update(Request $request, MpesaAgent $agent): RedirectResponse
    {
        $this->requirePermission('float_purchases.manage');

        $agent = $this->findAgent($agent->id);
        $data = $this->validateAgent($request, $agent);
        $phone = KenyanPhone::normalise($data['phone']);

        if ($phone === null) {
            return back()->withInput()->with('error', 'Enter a valid Kenyan phone number for the agent.');
        }

        $agent->update([...$data, 'phone' => $phone]);

        AuditService::log('mpesa_agent.updated', [
            'agent_number' => $data['agent_number'],
            'store_number' => $data['store_number'],
        ], $this->group());

        return back()->with('status', 'M-Pesa agent updated.');
    }

    public function toggle(MpesaAgent $agent): RedirectResponse
    {
        $this->requirePermission('float_purchases.manage');

        $agent = $this->findAgent($agent->id);
        $status = $agent->status === MpesaAgent::STATUS_ACTIVE
            ? MpesaAgent::STATUS_INACTIVE
            : MpesaAgent::STATUS_ACTIVE;

        $agent->update(['status' => $status]);

        AuditService::log('mpesa_agent.status_changed', [
            'status' => $status,
            'store_number' => $agent->store_number,
        ], $this->group());

        return back()->with('status', 'Agent '.strtolower($status).'.');
    }

    private function findAgent(int $id): MpesaAgent
    {
        return MpesaAgent::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAgent(Request $request, ?MpesaAgent $agent = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'agent_number' => ['required', 'string', 'max:30', 'regex:/^\d{4,15}$/'],
            'store_number' => [
                'required', 'string', 'max:30', 'regex:/^\d{4,15}$/',
                Rule::unique('mpesa_agents', 'store_number')
                    ->where(fn ($query) => $query->where('group_id', $this->group()->id))
                    ->ignore($agent?->id),
            ],
            'phone' => ['required', 'string', 'max:30'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
        ]);
    }
}
