<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Support\StudentPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentHistoryController extends Controller
{
    public function __invoke(Request $request, StudentPortal $portal)
    {
        $student = $request->user()->student;

        if (! $student) {
            return Inertia::render('StudentHistory', ['student' => null]);
        }

        QueueEntry::expireStale($student->id);

        return Inertia::render('StudentHistory', $portal->history($student));
    }
}
