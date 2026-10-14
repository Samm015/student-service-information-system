<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Support\StudentPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentDashboardController extends Controller
{
    public function __invoke(Request $request, StudentPortal $portal)
    {
        $student = $request->user()->student;

        // Admins can open this page but have no student profile.
        if (! $student) {
            return Inertia::render('Student', ['student' => null]);
        }

        QueueEntry::expireStale($student->id);

        return Inertia::render('Student', $portal->dashboard($student));
    }
}
