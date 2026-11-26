<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\AuditService;
use App\Support\GroupResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        /** @var \App\Models\User $user */
        $user = $this->actor();

        return view('profile.edit', [
            'user' => $user,
            'memberships' => $user->memberships()->with(['group', 'role'])->get(),
            'loginActivities' => $user->loginActivities()->latest('created_at')->limit(10)->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->actor();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $old = $user->only(['name', 'email', 'phone']);
        $user->update($data);

        AuditService::record('profile.updated', $user, $old, $user->only(['name', 'email', 'phone']));

        return back()->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->actor()->update(['password' => Hash::make($data['password'])]);

        AuditService::log('profile.password_changed', ['user_id' => $this->actor()->id], $this->context()->group());

        return back()->with('status', 'Password changed.');
    }

    public function switchGroup(Request $request, Group $group): RedirectResponse
    {
        $user = $this->actor();

        $membership = $user->memberships()
            ->where('group_id', $group->id)
            ->whereIn('status', ['active', 'approved'])
            ->first();

        abort_if($membership === null || $group->status !== 'active', 403);

        $request->session()->forget(GroupResolver::FULL_CONTROL_KEY);
        $request->session()->put(GroupResolver::SESSION_KEY, $group->id);

        return redirect()->route('dashboard')->with('status', 'Switched to '.$group->name.'.');
    }
}
