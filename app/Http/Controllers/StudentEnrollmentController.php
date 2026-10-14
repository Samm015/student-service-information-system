<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnrollmentRequest;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Support\StudentPortal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StudentEnrollmentController extends Controller
{
    /** Submit an enrollment request for the logged-in student. */
    public function store(StoreEnrollmentRequest $request)
    {
        $user    = $request->user();
        $student = $user->student;
        abort_unless($student, 403, 'Only students can submit an enrollment.');

        $data = $request->validated();

        try {
            $enrollment = DB::transaction(function () use ($student, $user, $data) {
                $existing = Enrollment::where('student_id', $student->id)
                    ->where('school_year', $data['school_year'])
                    ->where('semester', $data['semester'])
                    ->lockForUpdate()
                    ->first();

                // Pending/approved were already refused by the form request; recheck
                // inside the transaction in case two submissions raced.
                if ($existing && in_array($existing->status, [Enrollment::STATUS_PENDING, Enrollment::STATUS_APPROVED], true)) {
                    throw ValidationException::withMessages([
                        'semester' => 'You already have an enrollment for this term.',
                    ]);
                }

                if ($existing) {
                    // Rejected or cancelled earlier: re-submit on the same row (one row per term).
                    $existing->fill([
                        'year_level'   => $data['year_level'],
                        'submitted_at' => now(),
                        'reviewed_by'  => null,
                        'reviewed_at'  => null,
                        'remarks'      => null,
                    ])->save();
                    $existing->subjects()->sync($data['subject_ids']);
                    $existing->changeStatus(Enrollment::STATUS_PENDING, $user, 'Re-submitted by student');

                    return $existing;
                }

                $enrollment = Enrollment::create([
                    'student_id'   => $student->id,   // from the session, never from the form
                    'school_year'  => $data['school_year'],
                    'semester'     => $data['semester'],
                    'year_level'   => $data['year_level'],
                    'status'       => Enrollment::STATUS_PENDING,
                    'submitted_at' => now(),
                ]);
                $enrollment->subjects()->attach($data['subject_ids']);
                $enrollment->statusHistories()->create([
                    'from_status' => null,
                    'to_status'   => Enrollment::STATUS_PENDING,
                    'changed_by'  => $user->id,
                ]);

                return $enrollment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['semester' => 'You already have an enrollment for this term.']);
        }

        AuditLog::record('enrollment.submitted', $enrollment, null, ['subject_ids' => $data['subject_ids']]);

        return redirect()->route('student.dashboard')
            ->with('success', "Enrollment submitted for {$enrollment->semester}, SY {$enrollment->school_year}. It is now waiting for the Registrar's review.");
    }

    /** One enrollment request in detail. */
    public function show(Request $request, Enrollment $enrollment, StudentPortal $portal)
    {
        Gate::authorize('view', $enrollment); // 403 unless it is this student's own

        $e = $portal->presentEnrollment($enrollment->load('subjects:id,code,title,units'));

        return Inertia::render('RequestDetail', [
            'title'    => 'Enrollment Request',
            'back'     => ['href' => route('student.history', [], false), 'label' => 'Back to history'],
            'status'   => ['value' => $e['status'], 'label' => $e['status_label']],
            'summary'  => array_values(array_filter([
                ['Term', $e['term_label']],
                ['Year level', $e['year_level']],
                ['Total units', $e['units']],
                ['Submitted', $e['submitted_at']],
                $e['reviewed_at'] ? ['Reviewed', $e['reviewed_at']] : null,
                $e['remarks'] ? ['Remarks', $e['remarks']] : null,
            ])),
            'table' => [
                'title' => 'Subjects',
                'cols'  => ['Code', 'Subject', 'Units'],
                'rows'  => collect($e['subjects'])->map(fn ($s) => [$s['code'], $s['title'], $s['units']])->all(),
            ],
            'timeline' => $portal->timeline($enrollment),
        ]);
    }
}
