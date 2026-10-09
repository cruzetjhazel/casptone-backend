<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Lets a suspended or deactivated user see their account status without
 * being signed in. Login still refuses these accounts; this controller only
 * hands back a short-lived "recovery" token (NOT a Sanctum token, so it works
 * on none of the other API routes) that can read the status and, for
 * self-deactivated accounts, reactivate.
 */
class AccountRecoveryController extends Controller
{
    use ApiResponses;

    private const TOKEN_MINUTES = 30;

    /** POST /auth/account-status — email + password in, status + recovery token out. */
    public function status(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            abort(422, 'Invalid email or password.');
        }

        if ($user->isActive()) {
            return $this->success(['status' => 'active']);
        }

        return $this->success([
            ...$this->payload($user),
            'recovery_token' => $this->issueToken($user),
        ]);
    }

    /** POST /auth/account-recovery/info */
    public function info(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        return $this->success($this->payload($this->userFromToken($data['token'])));
    }

    /** POST /auth/account-recovery/reactivate — only for accounts the user deactivated themselves. */
    public function reactivate(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $user = $this->userFromToken($data['token']);

        if (($user->account_status?->value) !== 'deactivated') {
            abort(422, 'Only an account you deactivated yourself can be reactivated here. A suspended account must be reactivated by an administrator.');
        }

        $user->account_status = 'active';
        $user->deactivated_at = null;
        $user->reactivated_at = now(); // starts the 7-day wait before it can be deactivated again
        $user->save();

        return $this->success(['status' => 'active'], 'Account reactivated.');
    }

    private function payload(User $user): array
    {
        $status = $user->account_status?->value ?? 'active';

        return [
            'status' => $status,
            'name' => $user->name,
            'email' => $user->email,
            'suspension_reason' => $status === 'suspended' ? $user->suspension_reason : null,
            'suspended_at' => $user->suspended_at?->toISOString(),
            'deactivated_at' => $user->deactivated_at?->toISOString(),
            'can_reactivate' => $status === 'deactivated',
        ];
    }

    private function issueToken(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => 'account-recovery',
            'id' => $user->id,
            'exp' => now()->addMinutes(self::TOKEN_MINUTES)->timestamp,
        ]));
    }

    private function userFromToken(string $token): User
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            abort(401, 'This session expired. Please sign in again.');
        }

        if (($data['purpose'] ?? null) !== 'account-recovery' || ($data['exp'] ?? 0) < now()->timestamp) {
            abort(401, 'This session expired. Please sign in again.');
        }

        return User::find($data['id']) ?? abort(401, 'This session expired. Please sign in again.');
    }
}