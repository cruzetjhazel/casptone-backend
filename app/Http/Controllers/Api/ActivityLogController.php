<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use ApiResponses;

    /**
     * GET /photographer/activity-logs and GET /client/activity-logs
     * Scoped to the authenticated user's own activity.
     */
    public function mine(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);

        $logs = ActivityLog::with('causer')
            ->visibleTo($request->user())
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(ActivityLogResource::collection($logs));
    }

        /**
     * GET /admin/activity-logs
     * Full system log. Filters: search, role, category, from, to, action,
     * causer_id, archived. Archived logs are hidden unless archived=1.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->account_type === AccountType::Administrator, 403);

        $request->validate([
            'role' => ['sometimes', 'nullable', 'in:client,photographer,administrator,system'],
            'category' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(self::CATEGORY_SUBJECTS))],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ActivityLog::with('causer')->latest('created_at');

        $request->boolean('archived')
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');

        if ($request->filled('action')) {
            $query->where('action', 'like', $request->string('action').'%');
        }

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->integer('causer_id'));
        }

        if ($request->filled('search')) {
            $term = '%'.addcslashes((string) $request->string('search'), '%_\\').'%';
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', $term)
                    ->orWhere('action', 'like', $term)
                    ->orWhereHas('causer', fn ($c) => $c->where('name', 'like', $term));
            });
        }

        if ($role = $request->query('role')) {
            $role === 'system'
                ? $query->whereNull('causer_id')
                : $query->whereHas('causer', fn ($q) => $q->where('account_type', $role));
        }

        if ($category = $request->query('category')) {
            $types = array_map(fn ($m) => 'App\\Models\\'.$m, self::CATEGORY_SUBJECTS[$category]);
            $query->whereIn('subject_type', $types);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $logs = $query->paginate($request->integer('per_page', 20));

        return $this->success(ActivityLogResource::collection($logs));
    }

    /**
     * POST /admin/activity-logs/archive
     * Soft-archives the given logs (sets archived_at). Rows are never deleted.
     */
    public function archive(Request $request)
    {
        abort_unless($request->user()->account_type === AccountType::Administrator, 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $count = ActivityLog::whereNull('archived_at')
            ->whereIn('id', $data['ids'])
            ->update(['archived_at' => now()]);

        return $this->success(['archived' => $count], 'Activity logs archived.');
    }
        /** Category filter → subject model basenames stored in subject_type. */
    private const CATEGORY_SUBJECTS = [
        'bookings' => ['Booking'],
        'payments' => ['Payment', 'PaymentReference'],
        'users' => ['User'],
        'verifications' => ['PhotographerApplication'],
        'reports' => ['Report'],
        'reviews' => ['Review'],
    ];
}