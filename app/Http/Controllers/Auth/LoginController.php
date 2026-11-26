<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use App\Support\GroupResolver;
use App\Support\LandingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            $this->log($request, $credentials['email'], false, 'Invalid credentials.');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->status !== 'active') {
            Auth::logout();
            $this->log($request, $credentials['email'], false, 'Account is not active.');

            throw ValidationException::withMessages([
                'email' => 'This account is not active. Contact your group administrator.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget('mfa_verified_at');

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->log($request, $credentials['email'], true);

        $group = GroupResolver::current($user);

        if ($group) {
            $request->session()->put(GroupResolver::SESSION_KEY, $group->id);
        }

        $landing = LandingRoute::for($user, $group?->id);

        if ($user->mfa_enabled && ! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', $landing);
        }

        return redirect()->intended($landing);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function log(Request $request, string $email, bool $successful, ?string $reason = null): void
    {
        LoginActivity::create([
            'user_id' => Auth::id(),
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
