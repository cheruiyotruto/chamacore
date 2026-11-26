<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerryGoRoundController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MerryGoRoundCycle::class);

        $participations = MerryGoRoundParticipant::query()
            ->with(['cycle', 'payout'])
            ->where('member_id', $this->membership()->id)
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'data' => $participations->through(fn (MerryGoRoundParticipant $participant) => [
                'id' => $participant->id,
                'cycle_id' => $participant->merry_go_round_cycle_id,
                'cycle' => $participant->cycle?->name,
                'frequency' => $participant->cycle?->frequency,
                'contribution_amount' => $participant->cycle?->contribution_amount,
                'position' => $participant->position,
                'payout_date' => $participant->payout_date?->toDateString(),
                'status' => $participant->status,
                'total_contributed' => $participant->total_contributed,
                'total_received' => $participant->total_received,
                'payout_status' => $participant->payout?->status,
                'payout_amount' => $participant->payout?->amount,
            ]),
        ]);
    }

    public function show(Request $request, MerryGoRoundCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle);

        $participant = MerryGoRoundParticipant::query()
            ->with('payout')
            ->where('merry_go_round_cycle_id', $cycle->id)
            ->where('member_id', $this->membership()->id)
            ->firstOrFail();

        $contributions = MerryGoRoundContribution::query()
            ->where('participant_id', $participant->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('due_date')
            ->paginate(25);

        return response()->json([
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'contribution_amount' => $cycle->contribution_amount,
                'frequency' => $cycle->frequency,
                'start_date' => $cycle->start_date?->toDateString(),
                'end_date' => $cycle->end_date?->toDateString(),
                'status' => $cycle->status,
            ],
            'participant' => [
                'id' => $participant->id,
                'position' => $participant->position,
                'payout_date' => $participant->payout_date?->toDateString(),
                'status' => $participant->status,
                'total_contributed' => $participant->total_contributed,
                'total_received' => $participant->total_received,
                'payout' => $participant->payout ? [
                    'amount' => $participant->payout->amount,
                    'scheduled_date' => $participant->payout->scheduled_date?->toDateString(),
                    'paid_at' => $participant->payout->paid_at?->toIso8601String(),
                    'status' => $participant->payout->status,
                ] : null,
            ],
            'summary' => [
                'due' => (int) $participant->contributions()->whereIn('status', ['pending', 'late'])->sum('amount'),
                'paid' => (int) $participant->contributions()->where('status', 'paid')->sum('amount'),
                'penalties' => (int) $participant->contributions()->sum('penalty_amount'),
            ],
            'contributions' => $contributions->through(fn (MerryGoRoundContribution $contribution) => [
                'id' => $contribution->id,
                'amount' => $contribution->amount,
                'due_date' => $contribution->due_date?->toDateString(),
                'paid_at' => $contribution->paid_at?->toIso8601String(),
                'status' => $contribution->status,
                'penalty_amount' => $contribution->penalty_amount,
                'reference' => $contribution->reference,
            ]),
        ]);
    }
}
