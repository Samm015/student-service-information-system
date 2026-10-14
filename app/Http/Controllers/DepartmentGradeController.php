<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGradeRequest;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Support\AcademicTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Encode or update a grade.
 *  - Only for subjects owned by the user's own department.
 *  - A NEW grade needs an approved enrollment in that subject and term;
 *    an EXISTING grade can be corrected (policy: only the owning department).
 */
class DepartmentGradeController extends Controller
{
    public function store(StoreGradeRequest $request)
    {
        $user    = $request->user();
        $data    = $request->validated();
        $subject = Subject::findOrFail($data['subject_id']);

        if (! $user->isAdmin() && ($user->department_id === null || $subject->department_id !== $user->department_id)) {
            abort(403, 'You can only grade subjects owned by your own department.');
        }

        $student = Student::findByNumber($data['student_no']);
        $keys = [
            'student_id'  => $student->id,
            'subject_id'  => $subject->id,
            'school_year' => $data['school_year'],
            'semester'    => $data['semester'],
        ];

        $existing = Grade::where($keys)->first();

        if ($existing) {
            Gate::authorize('update', $existing);
        } else {
            $enrolled = Enrollment::where('student_id', $student->id)
                ->where('status', Enrollment::STATUS_APPROVED)
                ->where('school_year', $data['school_year'])
                ->where('semester', $data['semester'])
                ->whereHas('subjects', fn ($q) => $q->whereKey($subject->id))
                ->exists();

            if (! $enrolled) {
                throw ValidationException::withMessages([
                    'student_no' => "{$student->user->name} has no approved enrollment in {$subject->code} for "
                        . AcademicTerm::label($data['school_year'], $data['semester']) . '.',
                ]);
            }
        }

        $value   = number_format((float) $data['grade'], 2, '.', '');
        $remarks = (float) $value <= 3.00 ? 'passed' : 'failed';
        $old     = $existing?->only('grade', 'remarks');

        $grade = DB::transaction(fn () => Grade::updateOrCreate($keys, [
            'grade'      => $value,
            'remarks'    => $remarks,
            'encoded_by' => $user->id,
            'encoded_at' => now(),
        ]));

        AuditLog::record($existing ? 'grade.updated' : 'grade.encoded', $grade, $old, $grade->only('grade', 'remarks'));

        return back()->with('success', "Grade {$value} ({$remarks}) saved for {$student->user->name} in {$subject->code}.");
    }
}
