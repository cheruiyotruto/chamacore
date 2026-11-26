<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\Member;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\MemberLookupService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class MemberLookupController extends Controller
{
    /**
     * Section key => permission needed to reveal it.
     */
    private const PERMISSION_MAP = [
        'savings' => 'savings.view',
        'loans' => 'loans.view',
        'wallet' => 'wallet.view',
        'investments' => 'investments.view',
        'mgr' => 'mgr.view',
        'payments' => 'payments.view',
        'teller' => 'teller.view',
        'meetings' => 'meetings.view',
        'welfare' => 'welfare.view',
        'documents' => 'documents.view',
        'notifications' => 'notifications.view',
        'crm' => 'crm.view',
        'governance' => 'governance.view',
        'accounting' => 'accounting.view',
        'activity' => 'audit.view',
        'reports' => 'reports.view',
    ];

    public function __construct(
        private readonly MemberLookupService $lookup,
        private readonly DocumentService $documents,
    ) {
    }

    public function index(Request $request)
    {
        $this->requirePermission('members.view');

        $memberNo = $request->string('member_no')->trim()->value();
        $query = $request->string('q')->trim()->value();
        $term = $memberNo !== '' ? $memberNo : $query;
        $member = null;
        $error = null;
        $suggestions = collect();
        $results = collect();
        $can = $this->permissions();

        if ($memberNo !== '') {
            $member = Member::withoutGlobalScopes()
                ->where('group_id', $this->group()->id)
                ->whereRaw('LOWER(members.member_no) = ?', [mb_strtolower($memberNo)])
                ->first();

            if ($member) {
                $this->authorize('view', $member);
            } else {
                $error = 'No member with number “'.$memberNo.'” was found in this group.';
            }
        } elseif ($query !== '') {
            $results = $this->lookup
                ->search($query, (int) $this->group()->id, $can)
                ->filter(fn (array $result) => Gate::allows('view', $result['member']))
                ->values();

            if ($results->count() === 1) {
                return redirect()->route(
                    'members.lookup',
                    ['member_no' => $results->first()['member']->member_no]
                );
            }

            if ($results->isEmpty()) {
                $error = 'No members found for “'.$query.'”.';
            }
        }

        if ($term !== '') {
            $suggestions = $this->suggestions($term, $member);
        }

        $data = $member
            ? $this->lookup->lookup($member, $can, (string) ($this->group()->currency ?: 'KES'))
            : [];

        return view('members.lookup', [
            'term' => $term,
            'member' => $member,
            'error' => $error,
            'suggestions' => $suggestions,
            'results' => $results,
            'can' => $can,
            'data' => $data,
        ]);
    }

    /**
     * Stream passport photos, face videos, signatures and biometric captures
     * from the private EDMS. Both lookup and document-view permissions are
     * required, and resolution is scoped to the member and active tenant.
     */
    public function media(Member $member, string $key, Request $request)
    {
        $this->requirePermission('members.view');
        $this->requirePermission('documents.view');
        $this->authorize('view', $member);

        $documentId = $request->integer('document') ?: null;
        $document = $this->lookup->resolveMediaDocument($member, $key, $documentId);

        if ($document instanceof Document) {
            $this->authorize('view', $document);

            try {
                return $this->documents->preview($document, $this->actor());
            } catch (BusinessRuleException $e) {
                abort(404, $e->getMessage());
            }
        }

        $legacy = $this->lookup->legacyMediaPath($member, $key);

        if (! $legacy || ! DocumentService::isPreviewable($legacy['mime_type'])) {
            abort(404, 'Identity media not found.');
        }

        if (! Storage::disk('documents')->exists($legacy['path'])) {
            abort(404, 'Identity media not found.');
        }

        AuditService::log('member.identity_media.viewed', [
            'member_id' => $member->id,
            'member_no' => $member->member_no,
            'key' => $key,
            'path' => $legacy['path'],
        ], $this->group());

        return Storage::disk('documents')->response(
            $legacy['path'],
            $legacy['label'],
            ['Content-Type' => $legacy['mime_type']]
        );
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(): array
    {
        $context = $this->context();

        $permissions = [
            'overview' => true,
            'identity' => true,
        ];

        foreach (self::PERMISSION_MAP as $section => $permission) {
            $permissions[$section] = $context->hasPermission($permission);
        }

        return $permissions;
    }

    /**
     * Close matches for the search term. Every suggestion records the field
     * that made it look similar, so staff can see why it was offered.
     *
     * @return Collection<int, array{member: Member, label: string, value: string, score: int}>
     */
    private function suggestions(string $term, ?Member $excluded = null): Collection
    {
        $needle = $this->normalizeSuggestionText($term);

        if ($needle === '') {
            return collect();
        }

        $candidates = Member::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->when($excluded, fn ($query) => $query->where('members.id', '!=', $excluded->id))
            ->orderBy('member_no')
            ->limit(1000)
            ->get([
                'id', 'group_id', 'member_no', 'first_name', 'middle_name',
                'last_name', 'national_id', 'phone', 'email', 'workstation',
                'employer', 'address', 'status',
            ]);

        return $candidates
            ->map(function (Member $candidate) use ($needle): array {
                $best = ['label' => '', 'value' => '', 'score' => 0];

                foreach ($this->suggestionFields($candidate) as $label => $value) {
                    $value = (string) $value;
                    $score = $this->suggestionScore(
                        $needle,
                        $this->normalizeSuggestionText($value)
                    );

                    if ($score > $best['score']) {
                        $best = ['label' => $label, 'value' => $value, 'score' => $score];
                    }
                }

                return ['member' => $candidate] + $best;
            })
            ->filter(fn (array $row) => $row['score'] >= 50)
            ->sortByDesc('score')
            ->take(8)
            ->filter(fn (array $row) => Gate::allows('view', $row['member']))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionFields(Member $member): array
    {
        return [
            'Member number' => $member->member_no,
            'Name' => $member->full_name,
            'First name' => $member->first_name,
            'Last name' => $member->last_name,
            'ID number' => $member->national_id,
            'Phone' => $member->phone,
            'Email' => $member->email,
            'Workstation' => $member->workstation,
            'Employer' => $member->employer,
            'Home address' => $member->address,
        ];
    }

    private function normalizeSuggestionText(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';

        return mb_substr(mb_strtolower(trim($value)), 0, 120);
    }

    private function suggestionScore(string $term, string $candidate): int
    {
        if ($candidate === '') {
            return 0;
        }

        if ($candidate === $term) {
            return 100;
        }

        if (str_contains($candidate, $term) || str_contains($term, $candidate)) {
            return 90;
        }

        $length = max(strlen($term), strlen($candidate));

        if ($length === 0) {
            return 0;
        }

        $distance = $this->isAdjacentTransposition($term, $candidate)
            ? 1
            : levenshtein($term, $candidate);

        return max(0, (int) round((1 - $distance / $length) * 100));
    }

    private function isAdjacentTransposition(string $first, string $second): bool
    {
        if (strlen($first) !== strlen($second)) {
            return false;
        }

        $differences = [];
        $length = strlen($first);

        for ($index = 0; $index < $length; $index++) {
            if ($first[$index] !== $second[$index]) {
                $differences[] = $index;

                if (count($differences) > 2) {
                    return false;
                }
            }
        }

        return count($differences) === 2
            && $differences[1] === $differences[0] + 1
            && $first[$differences[0]] === $second[$differences[1]]
            && $first[$differences[1]] === $second[$differences[0]];
    }
}
