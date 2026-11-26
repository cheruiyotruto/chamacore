<?php

namespace App\Http\Controllers;

use App\Models\Communication;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CrmController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('crm.view');

        $leads = Lead::query()
            ->with(['assignee', 'convertedMember'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'leads')
            ->withQueryString();

        $followUps = FollowUp::query()
            ->with('lead')
            ->whereNull('completed_at')
            ->orderBy('due_at')
            ->limit(25)
            ->get();

        $tickets = SupportTicket::query()
            ->with('member')
            ->when($request->filled('ticket_status'), fn ($query) => $query->where('status', $request->string('ticket_status')))
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'tickets')
            ->withQueryString();

        return view('crm.index', [
            'leads' => $leads,
            'followUps' => $followUps,
            'tickets' => $tickets,
            'communications' => Communication::with('member')->orderByDesc('created_at')->limit(20)->get(),
            'leadStatuses' => ['new', 'contacted', 'qualified', 'converted', 'lost'],
            'ticketStatuses' => ['open', 'in_progress', 'resolved', 'closed'],
            'canManage' => $this->context()->hasPermission('crm.manage'),
        ]);
    }

    public function storeLead(Request $request): RedirectResponse
    {
        $this->requirePermission('crm.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'source' => ['required', 'in:walk_in,referral,online,campaign'],
            'assigned_to' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $lead = Lead::create([
            'group_id' => $this->group()->id,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'source' => $data['source'],
            'status' => 'new',
            'assigned_to' => $data['assigned_to'] ?? null,
            'notes' => $data['notes'] ?? null,
            'follow_up_at' => $data['follow_up_at'] ?? null,
        ]);

        AuditService::record('lead.created', $lead, null, $lead->only(['name', 'phone', 'source']));

        return back()->with('status', 'Lead captured.');
    }

    public function updateLead(Request $request, Lead $lead): RedirectResponse
    {
        $this->requirePermission('crm.manage');

        $data = $request->validate([
            'status' => ['required', 'in:new,contacted,qualified,converted,lost'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $old = $lead->only(['status', 'notes']);
        $lead->update($data);

        AuditService::record('lead.updated', $lead, $old, $lead->only(['status', 'notes']));

        return back()->with('status', 'Lead updated.');
    }

    public function storeFollowUp(Request $request, Lead $lead): RedirectResponse
    {
        $this->requirePermission('crm.manage');

        $data = $request->validate([
            'due_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        FollowUp::create([
            'group_id' => $this->group()->id,
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $this->actor()->id,
            'due_at' => $data['due_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Follow-up scheduled.');
    }

    public function completeFollowUp(FollowUp $followUp): RedirectResponse
    {
        $this->requirePermission('crm.manage');

        $followUp->update(['completed_at' => now()]);

        return back()->with('status', 'Follow-up completed.');
    }

    public function showTicket(SupportTicket $ticket): View
    {
        $this->requirePermission('crm.view');

        $ticket->load(['member', 'messages.sender']);

        return view('crm.ticket', [
            'ticket' => $ticket,
            'canManage' => $this->context()->hasPermission('crm.manage'),
        ]);
    }

    public function replyTicket(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->requirePermission('crm.manage');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        TicketMessage::create([
            'group_id' => $this->group()->id,
            'support_ticket_id' => $ticket->id,
            'sender_id' => $this->actor()->id,
            'body' => $data['body'],
            'is_internal' => $request->boolean('is_internal'),
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'status' => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
        ]);

        return back()->with('status', 'Reply added.');
    }

    public function updateTicket(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->requirePermission('crm.manage');

        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $ticket->update([
            ...$data,
            'closed_at' => in_array($data['status'], ['resolved', 'closed'], true) ? now() : null,
        ]);

        AuditService::record('ticket.updated', $ticket, null, $ticket->only(['status', 'priority', 'assigned_to']));

        return back()->with('status', 'Ticket updated.');
    }
}
