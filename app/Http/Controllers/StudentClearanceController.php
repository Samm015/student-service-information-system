<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Clearance;
use App\Models\ClearanceRequirement;
use App\Support\AcademicTerm;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Enrollment;

class StudentClearanceController extends Controller
{
    /**
     * Start the student's clearance for the CURRENT term (creates the record and
     * one item per requirement). The term comes from config, not from the form.
     * Signing is done by the offices later; students have no endpoint for it.
     */
    public function store(Request $request)
    {
        $student = $request->user()->student;
        abort_unless($student, 403, 'Only students can start a clearance.');

        $term = AcademicTerm::current();

        // A student must have an approved enrollment for the current term.
        $enrolled = Enrollment::where('student_id', $student->id)
            ->where('school_year', $term['school_year'])
            ->where('semester', $term['semester'])
            ->where('status', Enrollment::STATUS_APPROVED)
            ->exists();

        if (! $enrolled) {
            return redirect()->route('student.dashboard')
                ->with(
                    'error',
                    'You must have an approved enrollment for the current term before starting clearance.'
                );
        }

        $exists = Clearance::where('student_id', $student->id)
            ->where('school_year', $term['school_year'])
            ->where('semester', $term['semester'])
            ->exists();

        if ($exists) {
            return redirect()->route('student.dashboard')->with('error', 'Your clearance for this term has already been started.');
        }

        if (! ClearanceRequirement::where('is_active', true)->exists()) {
            return redirect()->route('student.dashboard')->with('error', 'Clearance requirements are not set up yet. Please contact the Registrar.');
        }

        try {
            $clearance = DB::transaction(fn () => Clearance::openFor($student, $term['school_year'], $term['semester']));
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('student.dashboard')->with('error', 'Your clearance for this term has already been started.');
        }

        AuditLog::record('clearance.started', $clearance);

        return redirect()->route('student.dashboard')
            ->with('success', 'Your clearance has been started. Each office will sign its requirement.');
    }
}
