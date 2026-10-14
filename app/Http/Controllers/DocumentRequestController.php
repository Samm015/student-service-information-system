<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequestRequest;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Student;
use App\Support\StaffPortal;
use App\Support\StudentPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DocumentRequestController extends Controller
{
    public function index(Request $request, StudentPortal $portal)
    {
        $student = $request->user()->student;

        // Staff open this page too (the sidebar shows it to everyone). Registration
        // processes requests here, the Cashier can look them up, others see a note.
        if (! $student) {
            if ($request->user()->can('viewAny', DocumentRequest::class)) {
                return app(StaffDocumentController::class)->index($request, app(StaffPortal::class));
            }

            return Inertia::render('Documents', ['is_student' => false, 'staff' => null]);
        }

        return Inertia::render('Documents', [
            'is_student' => true,
            'types'      => $portal->documentTypes(),
            'requests'   => $portal->documentRequests($student),
            'max_copies' => DocumentRequest::MAX_COPIES,
        ]);
    }

    public function store(StoreDocumentRequestRequest $request)
    {
        $user    = $request->user();
        $student = $user->student;
        abort_unless($student, 403, 'Only students can request documents.');

        $data = $request->validated();
        $type = DocumentType::findOrFail($data['document_type_id']);

        $documentRequest = DB::transaction(function () use ($student, $user, $type, $data) {
            // Lock the student's row so two quick submissions cannot both pass the duplicate check.
            Student::whereKey($student->id)->lockForUpdate()->first();

            $duplicate = DocumentRequest::where('student_id', $student->id)
                ->where('document_type_id', $type->id)
                ->whereIn('status', DocumentRequest::ACTIVE_STATUSES)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'document_type_id' => 'You already have an active request for this document.',
                ]);
            }

            $copies = (int) $data['copies'];

            $created = DocumentRequest::create([
                'student_id'       => $student->id,           // from the session, never from the form
                'document_type_id' => $type->id,
                'copies'           => $copies,
                'purpose'          => trim($data['purpose']),
                'status'           => DocumentRequest::STATUS_PENDING,
                'fee_total'        => $type->fee * $copies,   // snapshot, computed on the server
            ]);

            $created->statusHistories()->create([
                'from_status' => null,
                'to_status'   => DocumentRequest::STATUS_PENDING,
                'changed_by'  => $user->id,
            ]);

            return $created;
        });

        AuditLog::record('document_request.submitted', $documentRequest, null, [
            'document_type_id' => $type->id, 'copies' => $documentRequest->copies,
        ]);

        return redirect()->route('documents.index')
            ->with('success', "Request submitted. Your reference number is {$documentRequest->reference_no}.");
    }

    public function show(Request $request, DocumentRequest $documentRequest, StudentPortal $portal)
    {
        Gate::authorize('view', $documentRequest); // 403 unless it is this student's own

        // Staff get the processing view (Registration: next-step buttons; Cashier: read-only).
        if (! $request->user()->student) {
            return app(StaffDocumentController::class)->show($request, $documentRequest, $portal, app(StaffPortal::class));
        }

        $d = $portal->presentDocumentRequest($documentRequest->load('documentType:id,code,name', 'payments'));

        return Inertia::render('RequestDetail', [
            'title'    => "Document Request {$d['reference_no']}",
            'back'     => ['href' => route('documents.index', [], false), 'label' => 'Back to document requests'],
            'status'   => ['value' => $d['status'], 'label' => $d['status_label']],
            'summary'  => array_values(array_filter([
                ['Reference no.', $d['reference_no']],
                ['Document', $d['document']],
                ['Copies', $d['copies']],
                ['Purpose', $d['purpose']],
                ['Expected release', $d['expected_release_date'] ?? 'To be set by Registration'],
                $d['released_at'] ? ['Released', $d['released_at']] : null,
                ['Fee', '₱' . $d['fee_total']],
                ['Payment', app(StaffPortal::class)->paymentSummary($documentRequest)],
                ['Requested', $d['requested_at']],
                $d['remarks'] ? ['Remarks', $d['remarks']] : null,
            ])),
            'table'    => null,
            'timeline' => $portal->timeline($documentRequest),
            'cancel'   => $d['can_cancel'] && $request->user()->student?->id === $documentRequest->student_id
                ? ['url' => $d['cancel_url'], 'label' => 'Cancel request']
                : null,
        ]);
    }

    public function cancel(Request $request, DocumentRequest $documentRequest)
    {
        Gate::authorize('view', $documentRequest);

        if (! in_array($documentRequest->status, DocumentRequest::CANCELLABLE_STATUSES, true)) {
            return back()->with('error', 'This request can no longer be cancelled.');
        }

        Gate::authorize('cancel', $documentRequest); // owner only

        DB::transaction(function () use ($documentRequest, $request) {
            // An unpaid charge for a cancelled request must not stay in the cashier's pending list.
            $documentRequest->voidPendingPayments($request->user(), 'Request cancelled by student');
            $documentRequest->changeStatus(DocumentRequest::STATUS_CANCELLED, $request->user(), 'Cancelled by student');
        });

        AuditLog::record('document_request.cancelled', $documentRequest);

        return redirect()->route('documents.index')
            ->with('success', "Request {$documentRequest->reference_no} was cancelled.");
    }
}
