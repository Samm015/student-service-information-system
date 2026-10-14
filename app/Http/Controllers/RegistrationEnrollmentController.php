<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewEnrollmentRequest;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Support\AcademicTerm;
use App\Support\StudentPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class RegistrationEnrollmentController extends Controller
{
    /** Review screen: student, selected subjects, history, and the approve / reject buttons. */
    public function show(Enrollment $enrollment, StudentPortal $portal)
    {
        Gate::authorize('view', $enrollment);

        $enrollment->load(['student.user:id,name', 'student.department:id,name', 'subjects.department:id,code', 'reviewer:id,name']);
        $student = $enrollment->student;

        $passed = Grade::where('student_id', $student->id)->passed()
            ->whereIn('subject_id', $enrollment->subjects->pluck('id'))->pluck('subject_id')->all();

        $pending = $enrollment->status === Enrollment::STATUS_PENDING;

        return Inertia::render('StaffDetail', [
            'title'   => 'Enrollment Review',
            'back'    => ['href' => route('registration.dashboard', [], false), 'label' => 'Back to Registration'],
            'status'  => ['value' => $enrollment->status, 'label' => StudentPortal::statusLabel($enrollment->status)],
            'summary' => array_values(array_filter([
                ['Student', $student->user->name],
                ['Student no.', $student->student_no],
                ['Program', $student->program . ' · ' . $student->department?->name],
                ['Term', AcademicTerm::label($enrollment->school_year, $enrollment->semester)],
                ['Year level', $enrollment->year_level],
                ['Total units', (int) $enrollment->subjects->sum('units')],
                ['Submitted', $enrollment->submitted_at?->format('M d, Y g:i A')],
                $enrollment->reviewed_at ? ['Reviewed', $enrollment->reviewed_at->format('M d, Y g:i A') . ' by ' . ($enrollment->reviewer?->name ?? '—')] : null,
                $enrollment->remarks ? ['Remarks', $enrollment->remarks] : null,
            ])),
            'table' => [
                'title' => 'Selected subjects',
                'cols'  => ['Code', 'Subject', 'Units', 'Offered by', 'Note'],
                'rows'  => $enrollment->subjects->sortBy('code')->map(fn ($s) => [
                    $s->code, $s->title, $s->units, $s->department?->code,
                    ! $s->is_active ? 'No longer offered' : (in_array($s->id, $passed, true) ? 'Already passed' : ''),
                ])->values()->all(),
            ],
            'timeline' => $portal->timeline($enrollment),
            'review'   => $pending ? [
                'url'     => route('registration.enrollments.update', $enrollment, false),
                'method'  => 'patch',
                'title'   => 'Decision',
                'remarks' => ['label' => 'Remarks (required when rejecting)', 'required_for' => ['rejected']],
                'actions' => [
                    ['value' => 'approved', 'label' => 'Approve enrollment'],
                    ['value' => 'rejected', 'label' => 'Reject', 'confirm' => 'Reject this enrollment?'],
                ],
            ] : null,
        ]);
    }

    /** Approve or reject. Only a PENDING request can be reviewed, and only once. */
    public function update(ReviewEnrollmentRequest $request, Enrollment $enrollment)
    {
        $user    = $request->user();
        $status  = $request->validated('status');
        $remarks = trim((string) $request->validated('remarks')) ?: null;

        $result = DB::transaction(function () use ($enrollment, $user, $status, $remarks) {
            // Lock the row so two registrars cannot review the same request at once.
            $fresh = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Enrollment::STATUS_PENDING) {
                return ['error' => "This enrollment was already {$fresh->status}. Nothing was changed."];
            }

            if ($status === Enrollment::STATUS_APPROVED) {
                $fresh->load('subjects');

                if ($fresh->subjects->isEmpty()) {
                    return ['error' => 'This enrollment has no subjects, so it cannot be approved.'];
                }
                $closed = $fresh->subjects->where('is_active', false)->pluck('code');
                if ($closed->isNotEmpty()) {
                    return ['error' => 'These subjects are no longer offered: ' . $closed->implode(', ') . '. Reject the request so the student can pick again.'];
                }
            }

            $fresh->forceFill(['reviewed_by' => $user->id, 'reviewed_at' => now(), 'remarks' => $remarks])->save();
            $fresh->changeStatus($status, $user, $remarks);

            return ['enrollment' => $fresh];
        });

        if (isset($result['error'])) {
            return redirect()->route('registration.enrollments.show', $enrollment)->with('error', $result['error']);
        }

        AuditLog::record("enrollment.{$status}", $result['enrollment'], null, ['remarks' => $remarks]);

        return redirect()->route('registration.dashboard')
            ->with('success', 'Enrollment ' . $status . ' for ' . $result['enrollment']->student->user->name . '.');
    }
}
