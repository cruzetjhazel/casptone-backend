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

    /** Open bookings / pending payments an admin should know about before suspending. */
    public function suspensionImpact(User $user, Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $bookings = fn () => \App\Models\Booking::query()
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('photographer_id', $user->id));

        $pendingBookings = $bookings()->where('status', 'pending')->count();
        $confirmedBookings = $bookings()->where('status', 'confirmed')->count();

        $pendingPayments = \App\Models\Payment::query()
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('photographer_id', $user->id))
            ->whereIn('matching_status', ['submitted', 'pending_match', 'not_matched'])
            ->count();

        return $this->success([
            'pending_bookings' => $pendingBookings,
            'confirmed_bookings' => $confirmedBookings,
            'pending_payments' => $pendingPayments,
        ]);
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

        $fallout = app(\App\Actions\User\HandleSuspensionFalloutAction::class)->execute($user, $request->user());

        $message = 'Account suspended.';
        if ($fallout['cancelled'] > 0) {
            $message .= " {$fallout['cancelled']} pending booking".($fallout['cancelled'] > 1 ? 's were' : ' was').' cancelled.';
        }
        if ($fallout['needs_review'] > 0) {
            $message .= " {$fallout['needs_review']} confirmed booking".($fallout['needs_review'] > 1 ? 's need' : ' needs').' your review.';
        }

        return $this->success(new UserManagementResource($user->fresh(['photographerApplication'])), $message);
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
        /** Admin applies an approved contact-number change request. */
    public function updateContactNumber(User $user, Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->merge(['phone_number' => preg_replace('/[\s-]/', '', (string) $request->input('phone_number'))]);

        $data = $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^(?:\+63|0)9\d{9}$/'],
        ], [
            'phone_number.regex' => 'Enter a valid PH mobile number, e.g. 09171234567 or +639171234567.',
        ]);

        $old = $user->phone_number;
        $user->update(['phone_number' => $data['phone_number']]);

        app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
            causer: $request->user(),
            subject: $user,
            action: 'user.contact_number_updated',
            description: "Admin {$request->user()->name} changed the contact number for {$user->name} (user #{$user->id}) from {$old} to {$user->phone_number}.",
        );

        return $this->success(new UserManagementResource($user->fresh()), 'Contact number updated.');
    }
}