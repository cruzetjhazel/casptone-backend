<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserManagementResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate([
            'account_type' => ['sometimes', 'nullable', Rule::in(['client', 'photographer', 'administrator'])],
            'account_status' => ['sometimes', 'nullable', Rule::in(['active', 'suspended', 'deactivated'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $users = User::query()
            ->with(['photographerApplication'])
            ->where(function ($q) {
                $q->where('account_type', '!=', \App\Enums\AccountType::Photographer)
                  ->orWhereHas('photographerApplication', fn ($q) => $q->where('status', '!=', \App\Enums\PhotographerApplicationStatus::Draft));
            })
            ->when($request->query('account_type'), fn ($q, $type) => $q->where('account_type', $type))
            ->when($request->query('account_status'), fn ($q, $status) => $q->where('account_status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate(15);

        return $this->success(UserManagementResource::collection($users));
    }

    public function show(User $user, Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $user->load(['photographerApplication']);

        return $this->success(new UserManagementResource($user));
    }

    public function suspend(User $user, Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);
        abort_if($user->isAdministrator(), 403, 'Cannot suspend an administrator account.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user->account_status = 'suspended';
        $user->suspension_reason = $data['reason'];
        $user->suspended_at = now();
        $user->save();

        // Sign the user out everywhere right away.
        $user->tokens()->delete();

        return $this->success(new UserManagementResource($user->fresh(['photographerApplication'])), 'Account suspended.');
    }

    public function reactivate(User $user, Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $user->account_status = 'active';
        $user->suspension_reason = null;
        $user->suspended_at = null;
        $user->deactivated_at = null;
        $user->save();

        return $this->success(new UserManagementResource($user->fresh(['photographerApplication'])), 'Account reactivated.');
    }
}