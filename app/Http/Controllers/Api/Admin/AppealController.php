<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\SuspensionAppeal;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppealController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate(['status' => ['sometimes', 'nullable', Rule::in(['pending', 'approved', 'denied'])]]);

        $appeals = SuspensionAppeal::with('user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (SuspensionAppeal $a) => $this->present($a));

        return $this->success($appeals);
    }

    /** PATCH /admin/appeals/{appeal}/decide */
    public function decide(Request $request, SuspensionAppeal $appeal, LogActivityAction $activityLogger)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'deny'])],
            'response' => [Rule::requiredIf($request->input('decision') === 'deny'), 'nullable', 'string', 'max:1000'],
        ]);

        if ($appeal->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['This appeal was already decided.']]);
        }

        $approve = $data['decision'] === 'approve';

        $appeal->update([
            'status' => $approve ? 'approved' : 'denied',
            'admin_response' => $data['response'] ?? null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        if ($approve) {
            $user = $appeal->user;
            $user->account_status = AccountStatus::Active;
            $user->suspension_reason = null;
            $user->suspended_at = null;
            $user->save();
        }

        $activityLogger->execute(
            causer: $request->user(),
            subject: $appeal,
            action: $approve ? 'appeal.approved' : 'appeal.denied',
            description: ($approve ? 'Approved' : 'Denied')." the suspension appeal from {$appeal->user->name}",
        );

        return $this->success(
            $this->present($appeal->fresh('user')),
            $approve ? 'Appeal approved. Account reactivated.' : 'Appeal denied.'
        );
    }

    private function present(SuspensionAppeal $a): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status,
            'message' => $a->message,
            'attachments' => collect($a->attachments ?? [])->map(fn ($f) => ['url' => $f['url'], 'name' => $f['original_name']])->all(),
            'admin_response' => $a->admin_response,
            'created_at' => $a->created_at?->toIso8601String(),
            'decided_at' => $a->decided_at?->toIso8601String(),
            'user' => [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'email' => $a->user->email,
                'account_status' => $a->user->account_status->value,
                'suspension_reason' => $a->user->suspension_reason,
                'suspended_at' => $a->user->suspended_at ? \Illuminate\Support\Carbon::parse($a->user->suspended_at)->toIso8601String() : null,
            ],
        ];
    }
}