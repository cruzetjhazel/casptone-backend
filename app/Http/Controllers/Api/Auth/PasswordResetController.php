<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Traits\ApiResponses;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    use ApiResponses;

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        Password::sendResetLink($request->only('email'));

        // Always return a generic success message regardless of whether the
        // email exists, to avoid leaking account existence.
        return $this->success(null, 'If an account exists for that email, a password reset link has been sent.');
    }

    public function validateToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $user = Password::broker()->getUser(['email' => $data['email']]);

        // tokenExists() is false for unknown, expired (60 min) and already-used tokens.
        // Same generic message for every failure so it doesn't reveal whether the account exists.
        if (! $user || ! Password::broker()->tokenExists($user, $data['token'])) {
            return $this->error('This reset link is invalid or has expired. Please request a new one.', 422);
        }

        return $this->success(null, 'Link is valid.');
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
$user->tokens()->delete(); // sign out every existing session

                event(new PasswordReset($user));

                app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
                    causer: $user,
                    subject: $user,
                    action: 'account.password_reset',
                    description: 'Reset their password using an emailed link',
                );

                // Tell the account owner their password changed. A mail problem
                // must never undo a successful reset, so errors are only reported.
                try {
                    $user->notify(new PasswordChangedNotification());
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return $this->error('This reset link is invalid or has expired. Please request a new one.', 422);
        }

        return $this->success(null, 'Password has been reset successfully.');
    }
}