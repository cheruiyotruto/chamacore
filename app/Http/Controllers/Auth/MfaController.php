<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TotpService;
use App\Support\GroupResolver;
use App\Support\LandingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaController extends Controller
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function challenge(): View
    {
        return view('auth.mfa-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->mfa_secret || ! $this->totp->verifyOnce($user->mfa_secret, $request->input('code'), $user->id)) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid or expired.']);
        }

        $request->session()->put('mfa_verified_at', now()->timestamp);

        $groupId = $request->session()->get(GroupResolver::SESSION_KEY);

        return redirect()->intended(LandingRoute::for($user, $groupId ? (int) $groupId : null));
    }

    public function showSetup(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->mfa_secret) {
            $user->forceFill(['mfa_secret' => $this->generateSecret()])->save();
        }

        return view('auth.mfa-setup', [
            'secret' => $user->mfa_secret,
            'uri' => $this->totp->provisioningUri($user->mfa_secret, $user->email),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->mfa_secret || ! $this->totp->verifyOnce($user->mfa_secret, $request->input('code'), $user->id)) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => $user->mfa_secret,
        ])->save();

        // Any token issued before MFA was enabled must be re-issued through a
        // challenge, otherwise it would bypass the new requirement.
        $user->tokens()->delete();

        $request->session()->put('mfa_verified_at', now()->timestamp);

        return redirect()->route('profile.edit')->with('status', 'Multi-factor authentication enabled.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
            'code' => ['required', 'digits:6'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->mfa_secret || ! $this->totp->verify($user->mfa_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
        ])->save();

        $user->tokens()->delete();
        $this->totp->forgetReplayGuard($user->id);
        $request->session()->forget('mfa_verified_at');

        return redirect()->route('profile.edit')->with('status', 'Multi-factor authentication disabled.');
    }

    private function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }
}
