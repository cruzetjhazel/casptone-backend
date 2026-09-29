<?php

namespace App\Actions\Report;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SubmitReportAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * @param  array{target_type: string, reference_id?: ?string, reason: string, severity: string, details: string, requested_action: string}  $data
     * @param  UploadedFile[]  $evidence
     */
    public function execute(User $reporter, array $data, array $evidence = []): Report
    {
        $attachments = array_map(function (UploadedFile $file) {
            $path = $file->store('reports', 'public');

            return [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
            ];
        }, $evidence);

        $report = Report::create([
            'reporter_id' => $reporter->id,
            'target_type' => $data['target_type'],
            'reference_id' => $data['reference_id'] ?? null,
            'reason' => $data['reason'],
            'severity' => $data['severity'],
            'details' => $data['details'],
            'requested_action' => $data['requested_action'],
            'status' => ReportStatus::Submitted,
            'attachments' => $attachments,
        ]);

        $this->activityLogger->execute(
            causer: $reporter,
            subject: $report,
            action: 'report.submitted',
            description: "Filed a report ({$data['target_type']} / {$data['reason']})",
            metadata: ['severity' => $data['severity']],
        );

        return $report->fresh();
    }
}