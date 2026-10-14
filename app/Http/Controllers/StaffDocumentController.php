<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDocumentReleaseDateRequest;
use App\Http\Requests\UpdateDocumentRequestStatusRequest;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\Payment;
use App\Support\StaffPortal;
use App\Support\StudentPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Staff side of the document request lifecycle (Registration processes it):
 *
 *   pending -> awaiting_payment (fee > 0)  -> [Cashier records payment] -> processing
 *   pending -> processing        (no fee)
 *   processing -> ready -> released
 *   pending / awaiting_payment / processing -> rejected
 *
 * The student's own screens live in DocumentRequestController.
 */
class StaffDocumentController extends Controller
{
    /** List for /documents (called by DocumentRequestController::index for staff). */
    public function index(Request $request, StaffPortal $staff)
    {
        Gate::authorize('viewAny', DocumentRequest::class);

        return Inertia::render('Documents', [
            'is_student' => false,
            'staff'      => $staff->documentList($request) + [
                'can_process' => $request->user()->can('process', new DocumentRequest()),
            ],
        ]);
    }

    /** Detail + the next-step buttons (buttons only for Registration). */
    public function show(Request $request, DocumentRequest $documentRequest, StudentPortal $portal, StaffPortal $staff)
    {
        Gate::authorize('view', $documentRequest);

        $d = $documentRequest->load(['student.user:id,name', 'documentType:id,code,name,processing_days', 'payments.cashier:id,name', 'processor:id,name', 'releaser:id,name']);

        return Inertia::render('StaffDetail', [
            'title'   => "Document Request {$d->reference_no}",
            'back'    => ['href' => route('documents.index', [], false), 'label' => 'Back to document requests'],
            'status'  => ['value' => $d->status, 'label' => StudentPortal::statusLabel($d->status)],
            'summary' => array_values(array_filter([
                ['Student', $d->student->user->name . ' (' . $d->student->student_no . ')'],
                ['Document', $d->documentType?->name],
                ['Copies', $d->copies],
                ['Purpose', $d->purpose],
                ['Expected release', $d->expected_release_date?->format('M d, Y') ?? 'Not set yet'],
                ['Fee', '₱' . StaffPortal::money($d->fee_total) . ($d->requiresPayment() ? '' : ' (no payment required)')],
                ['Payment', $staff->paymentSummary($d)],
                ['Requested', $d->created_at?->format('M d, Y g:i A')],
                $d->processor ? ['Accepted by', $d->processor->name] : null,
                $d->releaser ? ['Released by', $d->releaser->name . ' · ' . $d->released_at?->format('M d, Y g:i A')] : null,
                $d->remarks ? ['Remarks', $d->remarks] : null,
            ])),
            'table'    => null,
            'timeline' => $portal->timeline($d),
            'tracker'  => $portal->documentTracker($d),
            'release_date' => $this->releaseDateForm($request, $d),
            'review'   => $this->nextSteps($request, $d),
        ]);
    }

    /** Registration moves the request along. Rules are enforced here, not in the browser. */
    public function update(UpdateDocumentRequestStatusRequest $request, DocumentRequest $documentRequest)
    {
        $user    = $request->user();
        $target  = $request->validated('status');
        $remarks = trim((string) $request->validated('remarks')) ?: null;

        $result = DB::transaction(function () use ($documentRequest, $user, $target, $remarks) {
            $d = DocumentRequest::whereKey($documentRequest->id)->lockForUpdate()->firstOrFail();
            $from = $d->status;

            $allowed = [
                DocumentRequest::STATUS_PENDING          => ['processing', 'rejected'],
                DocumentRequest::STATUS_AWAITING_PAYMENT => ['rejected'],
                DocumentRequest::STATUS_PROCESSING       => ['ready', 'rejected'],
                DocumentRequest::STATUS_READY            => ['released'],
            ];

            if (! in_array($target, $allowed[$from] ?? [], true)) {
                $hint = $from === DocumentRequest::STATUS_AWAITING_PAYMENT
                    ? ' It is waiting for the cashier to record the payment.' : '';

                return ['error' => "A request that is {$from} cannot be moved to {$target}.{$hint}"];
            }

                if ($target === 'rejected') {
                    $wasPaid = $d->isPaid();
                    $d->voidPendingPayments($user, 'Request rejected');
                    $d->forceFill(['remarks' => $remarks, 'processed_by' => $user->id, 'processed_at' => now()]);
                    $d->changeStatus(DocumentRequest::STATUS_REJECTED, $user, $remarks);

                    return ['request' => $d, 'message' => "Request {$d->reference_no} was rejected."
                        . ($wasPaid ? ' The fee was already paid: ask the Cashier to void the receipt to refund the student.' : '')];
                }

            if ($from === DocumentRequest::STATUS_PENDING) {
                // Accept: charge the fee first if there is one.
                if ($d->requiresPayment() && ! $d->isPaid()) {
                    $charge = Payment::create([
                        'student_id'          => $d->student_id,
                        'document_request_id' => $d->id,
                        'fee_type'            => 'document_fee',
                        'amount'              => $d->fee_total,
                    ]);
                    $charge->statusHistories()->create(['from_status' => null, 'to_status' => Payment::STATUS_PENDING, 'changed_by' => $user->id]);

                    $d->forceFill(['processed_by' => $user->id, 'processed_at' => now()]);
                    $d->changeStatus(DocumentRequest::STATUS_AWAITING_PAYMENT, $user, 'Fee of ₱' . StaffPortal::money($d->fee_total) . ' must be paid at the Cashier');

                    return ['request' => $d, 'message' => "Accepted. {$d->reference_no} now waits for the student's payment (₱" . StaffPortal::money($d->fee_total) . ').'];
                }

                $d->forceFill(['processed_by' => $user->id, 'processed_at' => now()]);
                $d->changeStatus(DocumentRequest::STATUS_PROCESSING, $user, $remarks ?: 'Accepted');

                return ['request' => $d, 'message' => "Accepted. {$d->reference_no} is now being processed."];
            }

            if ($target === 'ready') {
                if ($d->requiresPayment() && ! $d->isPaid()) {
                    return ['error' => 'The fee has not been paid yet, so the document cannot be marked ready.'];
                }
                $d->changeStatus(DocumentRequest::STATUS_READY, $user, $remarks ?: 'Ready for release');

                return ['request' => $d, 'message' => "{$d->reference_no} is ready for release."];
            }

            // released
            $d->forceFill(['released_by' => $user->id, 'released_at' => now()]);
            $d->changeStatus(DocumentRequest::STATUS_RELEASED, $user, $remarks ?: 'Released to the student');

            return ['request' => $d, 'message' => "{$d->reference_no} was released."];
        });

        if (isset($result['error'])) {
            return redirect()->route('documents.show', $documentRequest)->with('error', $result['error']);
        }

        AuditLog::record('document_request.' . $result['request']->status, $result['request'], null, ['remarks' => $remarks]);

        return redirect()->route('documents.show', $documentRequest)->with('success', $result['message']);
    }

    /**
     * Registration sets or changes the date the student can expect the document.
     * The student only ever reads it. The change is written to the request's history so the
     * student can see it, and to the audit log (who changed it, from what, to what).
     */
    public function releaseDate(UpdateDocumentReleaseDateRequest $request, DocumentRequest $documentRequest)
    {
        $user = $request->user();
        $date = $request->validated('expected_release_date');

        $result = DB::transaction(function () use ($documentRequest, $user, $date) {
            $d = DocumentRequest::whereKey($documentRequest->id)->lockForUpdate()->firstOrFail();

            if (! in_array($d->status, DocumentRequest::ACTIVE_STATUSES, true)) {
                return ['error' => "A request that is {$d->status} no longer has a release date to set."];
            }

            $old = $d->expected_release_date?->format('Y-m-d');
            $d->expected_release_date = $date;
            $d->save();

            $pretty = $d->expected_release_date->format('M d, Y');
            $d->statusHistories()->create([
                'from_status' => $d->status,
                'to_status'   => $d->status,
                'changed_by'  => $user->id,
                'remarks'     => $old
                    ? "Expected release date changed to {$pretty}"
                    : "Expected release date set to {$pretty}",
            ]);

            return ['request' => $d, 'old' => $old, 'pretty' => $pretty];
        });

        if (isset($result['error'])) {
            return redirect()->route('documents.show', $documentRequest)->with('error', $result['error']);
        }

        AuditLog::record(
            'document_request.release_date',
            $result['request'],
            ['expected_release_date' => $result['old']],
            ['expected_release_date' => $date],
        );

        return redirect()->route('documents.show', $documentRequest)
            ->with('success', "Expected release date for {$result['request']->reference_no} is {$result['pretty']}.");
    }

    /** The "expected release date" form, for Registration on a request that is still open. */
    private function releaseDateForm(Request $request, DocumentRequest $d): ?array
    {
        if (! $request->user()->can('process', $d) || ! in_array($d->status, DocumentRequest::ACTIVE_STATUSES, true)) {
            return null;
        }

        return [
            'url'   => route('documents.release-date', $d, false),
            'value' => $d->expected_release_date?->format('Y-m-d') ?? '',
            'min'   => today()->format('Y-m-d'),
            'hint'  => $d->documentType
                ? "{$d->documentType->name} usually takes about {$d->documentType->processing_days} working day(s)."
                : null,
        ];
    }

    /** The buttons the current staff member may press right now (empty = none). */
    private function nextSteps(Request $request, DocumentRequest $d): ?array
    {
        if (! $request->user()->can('process', $d)) {
            return null;
        }

        $actions = match ($d->status) {
            DocumentRequest::STATUS_PENDING => [
                ['value' => 'processing', 'label' => $d->requiresPayment() ? 'Accept and request payment' : 'Accept and start processing'],
                ['value' => 'rejected', 'label' => 'Reject', 'confirm' => 'Reject this request?'],
            ],
            DocumentRequest::STATUS_AWAITING_PAYMENT => [
                ['value' => 'rejected', 'label' => 'Reject', 'confirm' => 'Reject this request?'],
            ],
            DocumentRequest::STATUS_PROCESSING => [
                ['value' => 'ready', 'label' => 'Mark as ready for release'],
                ['value' => 'rejected', 'label' => 'Reject', 'confirm' => 'Reject this request?'],
            ],
            DocumentRequest::STATUS_READY => [
                ['value' => 'released', 'label' => 'Mark as released'],
            ],
            default => [],
        };

        if ($actions === []) {
            return null;
        }

        return [
            'url'     => route('documents.status', $d, false),
            'method'  => 'patch',
            'title'   => $d->status === DocumentRequest::STATUS_AWAITING_PAYMENT ? 'Waiting for payment' : 'Next step',
            'note'    => $d->status === DocumentRequest::STATUS_AWAITING_PAYMENT
                ? 'The student pays at the Cashier. This request moves to processing automatically once the payment is recorded.' : null,
            'remarks' => ['label' => 'Remarks (required when rejecting)', 'required_for' => ['rejected']],
            'actions' => $actions,
        ];
    }
}
