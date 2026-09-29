<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\RegisterClientAction;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterClientRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Actions\Photographer\RegisterPhotographerAction;
use App\Http\Requests\RegisterPhotographerRequest;

class AuthController extends Controller
{
    use ApiResponses;

    public function register(RegisterClientRequest $request, RegisterClientAction $action)
    {
        $user = $action->execute($request->validated());
        $token = $user->createToken('api')->plainTextToken;

        return $this->success(
            ['user' => new UserResource($user), 'token' => $token],
            'Account created successfully.',
            201
        );
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->account_status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success(
            ['user' => new UserResource($user), 'token' => $token],
            'Logged in successfully.'
        );
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    public function me(Request $request)
    {
        return $this->success(new UserResource($request->user()));
    }
    public function registerPhotographer(RegisterPhotographerRequest $request, RegisterPhotographerAction $action)
    {
        $user = $action->execute($request->validated());
        $token = $user->createToken('api')->plainTextToken;

        return $this->success(
            [
                'user' => new UserResource($user),
                'application' => new \App\Http\Resources\PhotographerApplicationResource($user->photographerApplication),
                'token' => $token,
            ],
            'Photographer account created. Complete and submit your application to begin the review process.',
            201
        );
    }
    public function checkEmail(Request $request)
{
    $email = strtolower(trim((string) $request->input('email')));

    if (!preg_match('/^[a-z0-9._%+\-]+@gmail\.com$/', $email)) {
        return response()->json(['available' => false, 'reason' => 'invalid_domain']);
    }

    $exists = User::whereRaw('LOWER(email) = ?', [$email])->exists();

    return response()->json([
        'available' => !$exists,
        'reason' => $exists ? 'taken' : null,
    ]);
}
}