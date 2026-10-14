<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Subject;
use App\Support\AcademicTerm;
use App\Support\StaffPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Department portal home: clearance sign-offs and grade encoding for ONE department. */
class DepartmentController extends Controller
{
    public function __invoke(Request $request, StaffPortal $staff)
    {
        $user = $request->user();
        $dept = $user->department;

        // Admins (and unassigned accounts) have no department to work for.
        if (! $dept) {
            return Inertia::render('Department', ['department' => null]);
        }

        $term     = AcademicTerm::current();
        $toGrade  = $staff->gradingQuery($dept->id, $term);
        $toGradeN = (clone $toGrade)->count();

        return Inertia::render('Department', [
            'department' => ['code' => $dept->code, 'name' => $dept->name],
            'stats' => [
                'clearance' => (string) $staff->openClearanceCount($dept->id),
                'enlisted'  => (string) $staff->enlistedCount($dept->id, $term),
                'to_grade'  => (string) $toGradeN,
            ],
            'clearance' => $staff->clearanceList($request, $dept->id),
            'grading'   => [
                'subjects' => Subject::where('department_id', $dept->id)->where('is_active', true)->orderBy('code')->get()
                    ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->code} · {$s->title}"])->values(),
                'terms'    => $staff->gradeTerms(),
                'term'     => $term['school_year'] . '|' . $term['semester'],
                'to_grade' => (clone $toGrade)
                    ->select('st.student_no', 'u.name as student', 's.id as subject_id', 's.code', 's.title')
                    ->orderBy('s.code')->orderBy('u.name')->limit(25)->get()
                    ->map(fn ($r) => (array) $r)->values(),
                'to_grade_total' => $toGradeN,
                'recent'   => Grade::query()
                    ->whereHas('subject', fn ($s) => $s->where('department_id', $dept->id))
                    ->with(['student.user:id,name', 'subject:id,code'])
                    ->orderByDesc('encoded_at')->orderByDesc('id')->limit(10)->get()
                    ->map(fn (Grade $g) => [
                        'id'         => $g->id,
                        'student_no' => $g->student?->student_no,
                        'student'    => $g->student?->user?->name,
                        'code'       => $g->subject?->code,
                        'term_label' => AcademicTerm::label($g->school_year, $g->semester),
                        'grade'      => $g->grade,
                        'remarks'    => $g->remarks ? ucfirst($g->remarks) : null,
                        'encoded_at' => $g->encoded_at?->format('M d, Y g:i A'),
                    ])->values(),
            ],
        ]);
    }
}
