<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(): View
    {
        $tickets = SupportTicket::query()
            ->where('member_id', $this->membership()->id)
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('portal.support.index', ['tickets' => $tickets]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'category' => ['required', 'in:general,loan,savings,wallet,account,technical'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = SupportTicket::create([
            'group_id' => $this->group()->id,
            'member_id' => $this->membership()->id,
            'subject' => $data['subject'],
            'category' => $data['category'],
            'priority' => $data['priority'],
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        TicketMessage::create([
            'group_id' => $this->group()->id,
            'support_ticket_id' => $ticket->id,
            'sender_id' => $this->actor()->id,
            'body' => $data['message'],
        ]);

        return redirect()->route('portal.support.show', $ticket)->with('status', 'Support ticket created.');
    }

    public function show(SupportTicket $ticket): View
    {
        abort_unless($ticket->member_id === $this->membership()->id, 403);

        $ticket->load(['messages' => fn ($query) => $query->where('is_internal', false), 'messages.sender']);

        return view('portal.support.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($ticket->member_id === $this->membership()->id, 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        if (in_array($ticket->status, ['closed', 'resolved'], true)) {
            return back()->with('error', 'This ticket is closed. Please open a new ticket.');
        }

        TicketMessage::create([
            'group_id' => $this->group()->id,
            'support_ticket_id' => $ticket->id,
            'sender_id' => $this->actor()->id,
            'body' => $data['body'],
        ]);

        $ticket->update(['last_reply_at' => now(), 'status' => 'open']);

        return back()->with('status', 'Reply sent.');
    }
}
