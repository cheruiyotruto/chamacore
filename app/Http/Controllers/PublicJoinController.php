<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\MemberInvitation;
use App\Services\MemberApplicationService;
use App\Services\MemberEdmsService;
use App\Support\JoinRequirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public, opt-in member applications. Every group here was explicitly enabled
 * by a super admin; applications are always pending until a decision is made.
 */
class PublicJoinController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->filled('invitation')) {
            return $this->openInvitation((string) $request->input('invitation'));
        }

        $groups = Group::query()
            ->publiclyJoinable()
            ->orderBy('name')
            ->get();

        return view('marketing.join.index', [
            'groups' => $groups,
        ]);
    }

    /**
     * Invitation links and open applications share one public entry point.
     * Accept a pasted URL as well as the bare token.
     */
    private function openInvitation(string $value): RedirectResponse
    {
        $token = $this->invitationToken($value);

        if ($token === '' || ! MemberInvitation::withoutGlobalScopes()->where('token', $token)->exists()) {
            return redirect()->route('public.join.index')
                ->with('error', 'We could not find that invitation. Check the link or apply to an open group below.');
        }

        return redirect()->route('join.show', $token);
    }

    private function invitationToken(string $value): string
    {
        $value = trim($value);

        if (preg_match('~/join/([^/?#]+)~', $value, $matches) === 1) {
            $value = urldecode($matches[1]);
        }

        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }

    public function show(Group $group): View
    {
        abort_unless($group->isPubliclyJoinable(), 404, 'This group is not accepting public applications.');

        return view('marketing.join.apply', [
            'group' => $group,
            'requirements' => JoinRequirements::for($group),
            'action' => route('public.join.store', $group),
        ]);
    }

    public function store(
        Request $request,
        Group $group,
        MemberApplicationService $applications,
        MemberEdmsService $edms
    ): RedirectResponse {
        abort_unless($group->isPubliclyJoinable(), 404, 'This group is not accepting public applications.');

        $requirements = JoinRequirements::for($group);
        $data = $request->validate($requirements->validationRules());

        $photo = $requirements->mediaEnabled('passport_photo') ? $request->file('passport_photo') : null;
        $video = $requirements->mediaEnabled('face_video') ? $request->file('face_video') : null;

        $identityErrors = $requirements->identityErrors($photo !== null, $video !== null);

        if ($identityErrors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($identityErrors);
        }

        [$member] = $applications->submit(
            $group,
            $data,
            $request,
            $requirements,
            $edms,
            'public website'
        );

        return redirect()->route('public.join.submitted', $group)
            ->with('status', 'Thank you, '.$member->first_name.'. Your application has been received and is awaiting approval.');
    }

    public function submitted(Group $group): View
    {
        abort_unless($group->isPubliclyJoinable(), 404, 'This group is not accepting public applications.');

        return view('marketing.join.submitted', [
            'group' => $group,
        ]);
    }
}
