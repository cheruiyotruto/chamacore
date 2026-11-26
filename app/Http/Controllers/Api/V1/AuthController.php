<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\LoginActivity;
use App\Models\Member;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, TotpService $totp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'in:android,ios,web'],
            'group_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'digits:6'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->log($request, $data['email'], false, 'Invalid credentials.');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->status !== 'active') {
            $this->log($request, $data['email'], false, 'Account is not active.');

            throw ValidationException::withMessages([
                'email' => 'This account is not active. Contact your group administrator.',
            ]);
        }

        if ($user->mfa_enabled) {
            if (! $data['code'] || ! $user->mfa_secret || ! $totp->verifyOnce($user->mfa_secret, $data['code'], $user->id)) {
                $this->log($request, $data['email'], false, 'Invalid MFA code.');

                return response()->json([
                    'message' => 'A valid multi-factor authentication code is required.',
                    'mfa_required' => true,
                    'errors' => ['code' => ['The authentication code is invalid or expired.']],
                ], 422);
            }
        }

        $memberships = $this->memberships($user);

        if ($memberships->isEmpty()) {
            $this->log($request, $data['email'], false, 'No active group membership.');

            throw ValidationException::withMessages([
                'email' => 'This account has no active group membership.',
            ]);
        }

        $membership = $data['group_id']
            ? $memberships->firstWhere('group_id', (int) $data['group_id'])
            : $memberships->first();

        if (! $membership) {
            abort(403, 'You do not have an active membership in that group.');
        }

        $abilities = [
            'group:'.$membership->group_id,
            'role:'.($membership->role?->key ?? 'member'),
        ];

        if ($user->mfa_enabled) {
            $abilities[] = 'mfa:verified';
        }

        $token = $user->createToken($data['device_name'], $abilities);

        $token->accessToken->forceFill(['expires_at' => now()->addDays(30)])->save();

        if (! empty($data['platform'])) {
            DeviceToken::query()->updateOrCreate(
                ['token' => $data['device_name'].'|'.$token->accessToken->id],
                ['user_id' => $user->id, 'platform' => $data['platform'], 'last_used_at' => now()]
            );
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->log($request, $data['email'], true);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->userPayload($user),
            'active_membership' => $this->membershipPayload($membership),
            'groups' => $memberships->map(fn (Member $membership) => $this->membershipPayload($membership))->values(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->membership();

        return response()->json([
            'user' => $this->userPayload($user),
            'membership' => $this->membershipPayload($membership),
            'group' => [
                'id' => $this->group()->id,
                'name' => $this->group()->name,
                'currency' => $this->group()->currency,
                'timezone' => $this->group()->timezone,
            ],
            'permissions' => $membership->role?->permissions ?? [],
            'is_staff' => $this->context()->isStaff(),
        ]);
    }

    public function groups(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->memberships($user)
                ->map(fn (Member $membership) => $this->membershipPayload($membership))
                ->values(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function changePassword(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Invalidate every other token after a credential change.
        $currentId = $request->user()->currentAccessToken()?->id;
        $user->tokens()->when($currentId, fn ($query) => $query->where('id', '!=', $currentId))->delete();

        return response()->noContent();
    }

    public function registerDevice(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);

        DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'last_used_at' => now(),
            ]
        );

        return response()->noContent();
    }

    public function removeDevice(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->noContent();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Member>
     */
    private function memberships(User $user)
    {
        return $user->memberships()
            ->withoutGlobalScopes()
            ->whereIn('status', ['active', 'approved'])
            ->with(['group', 'role'])
            ->get()
            ->filter(fn (Member $membership) => $membership->group && $membership->group->status === 'active')
            ->sortBy(fn (Member $membership) => $membership->group->name)
            ->values();
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'mfa_enabled' => $user->mfa_enabled,
            'is_super_admin' => $user->isSuperAdmin(),
        ];
    }

    private function membershipPayload(Member $membership): array
    {
        return [
            'member_id' => $membership->id,
            'member_no' => $membership->member_no,
            'name' => $membership->full_name,
            'status' => $membership->status,
            'group' => [
                'id' => $membership->group?->id,
                'name' => $membership->group?->name,
                'slug' => $membership->group?->slug,
                'currency' => $membership->group?->currency,
            ],
            'role' => $membership->role ? [
                'key' => $membership->role->key,
                'name' => $membership->role->name,
                'permissions' => $membership->role->permissions ?? [],
            ] : null,
        ];
    }

    private function log(Request $request, string $email, bool $successful, ?string $reason = null): void
    {
        LoginActivity::create([
            'user_id' => $request->user()?->id,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
