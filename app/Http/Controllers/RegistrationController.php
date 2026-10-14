<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\Enrollment;
use App\Models\QueueEntry;
use App\Support\StaffPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Registration portal home: stats, queue controls and the enrollment applications list. */
class RegistrationController extends Controller
{
    public function __invoke(Request $request, StaffPortal $staff)
    {
        $queue = $staff->queuePanel($request->user());

        // "Completed today" = work this office finished today, counted from the database.
        $reviewed = Enrollment::where('reviewed_at', '>=', today())->count();
        $released = DocumentRequest::where('released_at', '>=', today())->count();
        $served   = QueueEntry::whereDate('queue_date', today())
            ->where('status', QueueEntry::STATUS_COMPLETED)
            ->whereIn('service', QueueEntry::REGISTRATION_SERVICES)
            ->count();

        return Inertia::render('Registration', [
            'stats' => [
                'now_serving'      => $queue['stats']['now_serving'],
                'in_queue'         => (string) $queue['stats']['waiting'],
                'pending'          => (string) Enrollment::where('status', Enrollment::STATUS_PENDING)->count(),
                'completed_today'  => (string) ($reviewed + $released + $served),
                'completed_hint'   => "{$reviewed} enrollments · {$released} documents · {$served} served",
                'documents_to_do'  => (string) DocumentRequest::whereIn('status', [
                    DocumentRequest::STATUS_PENDING, DocumentRequest::STATUS_PROCESSING, DocumentRequest::STATUS_READY,
                ])->count(),
            ],
            'queue'       => $queue,
            'enrollments' => $staff->enrollmentList($request),
            'documents'   => $staff->documentsToProcess(),
            'clearance' => $request->user()->department_id
                ? $staff->clearanceList($request, $request->user()->department_id)
                : null,
        ]);
    }
}
