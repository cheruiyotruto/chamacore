<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->where('member_id', $this->membership()->id)
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'data' => $tickets->through(fn (SupportTicket $ticket) => $this->payload($ticket)),
        ]);
    }

    public function store(Request $request): JsonResponse
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

        return response()->json([
            'message' => 'Support ticket created.',
            'ticket' => $this->payload($ticket),
        ], 201);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        abort_unless($ticket->member_id === $this->membership()->id, 403);

        $ticket->load(['messages' => fn ($query) => $query->where('is_internal', false), 'messages.sender']);

        return response()->json([
            'ticket' => $this->payload($ticket),
            'messages' => $ticket->messages->map(fn (TicketMessage $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'sender' => $message->sender?->name,
                'is_staff' => $this->senderIsStaff($message, $ticket),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_unless($ticket->member_id === $this->membership()->id, 403);

        if (in_array($ticket->status, ['closed', 'resolved'], true)) {
            return response()->json(['message' => 'This ticket is closed. Please open a new ticket.'], 422);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $message = TicketMessage::create([
            'group_id' => $this->group()->id,
            'support_ticket_id' => $ticket->id,
            'sender_id' => $this->actor()->id,
            'body' => $data['body'],
        ]);

        $ticket->update(['last_reply_at' => now(), 'status' => 'open']);

        return response()->json([
            'message' => 'Reply sent.',
            'reply' => [
                'id' => $message->id,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'uuid' => $ticket->uuid,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    private function senderIsStaff(TicketMessage $message, SupportTicket $ticket): bool
    {
        if (! $message->sender) {
            return false;
        }

        if ($message->sender->isSuperAdmin()) {
            return true;
        }

        return \App\Models\Member::withoutGlobalScopes()
            ->where('group_id', $ticket->group_id)
            ->where('user_id', $message->sender->id)
            ->whereHas('role', fn ($query) => $query->where('key', '!=', 'member'))
            ->exists();
    }
}
