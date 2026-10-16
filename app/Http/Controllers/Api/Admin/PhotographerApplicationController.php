<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Photographer\ApprovePhotographerApplicationAction;
use App\Actions\Photographer\RejectPhotographerApplicationAction;
use App\Actions\Photographer\RequestPhotographerApplicationRevisionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectPhotographerApplicationRequest;
use App\Http\Requests\RequestRevisionRequest;
use App\Http\Resources\PhotographerApplicationResource;
use App\Models\PhotographerApplication;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PhotographerApplicationController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        $this->authorize('viewAny', PhotographerApplication::class);

        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([
                'draft', 'pending_review', 'revision_requested', 'approved', 'rejected',
            ])],
        ]);

        $applications = PhotographerApplication::with('user')
            ->when(
                $request->query('status'),
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->where('status', '!=', 'draft')
            )
            ->latest()
            ->paginate(15);

        return $this->success(PhotographerApplicationResource::collection($applications));
    }

    public function show(PhotographerApplication $photographerApplication)
    {
        $this->authorize('view', $photographerApplication);

        return $this->success(new PhotographerApplicationResource($photographerApplication->load('user', 'reviewer')));
    }

    public function approve(PhotographerApplication $photographerApplication, ApprovePhotographerApplicationAction $action, Request $request)
    {
        $this->authorize('approve', $photographerApplication);

        $application = $action->execute($photographerApplication, $request->user());

        return $this->success(new PhotographerApplicationResource($application), 'Application approved.');
    }

    public function reject(RejectPhotographerApplicationRequest $request, PhotographerApplication $photographerApplication, RejectPhotographerApplicationAction $action)
    {
        $this->authorize('reject', $photographerApplication);

        $application = $action->execute(
            $photographerApplication,
            $request->user(),
            $request->validated('reason'),
            $request->boolean('can_reapply', true)
        );

        return $this->success(new PhotographerApplicationResource($application), 'Application rejected.');
    }

    public function requestRevision(RequestRevisionRequest $request, PhotographerApplication $photographerApplication, RequestPhotographerApplicationRevisionAction $action)
    {
        $this->authorize('requestRevision', $photographerApplication);

        $application = $action->execute($photographerApplication, $request->user(), $request->validated('notes'));

        return $this->success(new PhotographerApplicationResource($application), 'Revision requested.');
    }

    public function downloadDocument(PhotographerApplication $photographerApplication, string $type)
    {
        $this->authorize('view', $photographerApplication);

        $field = match ($type) {
            'government-id' => 'government_id_path',
            'selfie-with-id' => 'selfie_with_id_path',
            'business-permit' => 'business_permit_path',
            default => abort(404),
        };

        $path = $photographerApplication->{$field};

        abort_if(! $path || ! Storage::disk('local')->exists($path), 404, 'Document not found.');

        return Storage::disk('local')->download($path);
    }
}