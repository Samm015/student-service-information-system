<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQueueEntryRequest;
use App\Models\AuditLog;
use App\Models\QueueEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StudentQueueController extends Controller
{
    /** Take a queue number for the logged-in student. */
    public function store(StoreQueueEntryRequest $request)
    {
        $student = $request->targetStudent(); // always the logged-in student for students
        abort_unless($student, 403, 'Only students can take a queue number here.');

        $service = $request->validated('service');
        $entry   = null;

        // Two students taking a number at the same instant can collide on the
        // daily number; retry a few times. A collision on "one active entry per
        // service" means the student double-clicked.
        for ($attempt = 1; $attempt <= 3 && ! $entry; $attempt++) {
            try {
                $entry = QueueEntry::issue($student, $service);
            } catch (UniqueConstraintViolationException $e) {
                if (QueueEntry::hasActive($student->id, $service)) {
                    throw ValidationException::withMessages([
                        'service' => 'You already have an active queue number for this service.',
                    ]);
                }
                if ($attempt === 3) {
                    throw $e;
                }
            }
        }

        AuditLog::record('queue.joined', $entry, null, ['service' => $service]);

        return redirect()->route('student.dashboard')
            ->with('success', "Your queue number is {$entry->queue_code} for " . QueueEntry::serviceLabel($service) . '.');
    }

    /** Cancel the student's own waiting entry. */
    public function cancel(Request $request, QueueEntry $queueEntry)
    {
        Gate::authorize('view', $queueEntry); // 403 for anyone else's entry

        if ($queueEntry->status !== QueueEntry::STATUS_WAITING) {
            return back()->with('error', 'This queue number can no longer be cancelled.');
        }

        Gate::authorize('cancel', $queueEntry);

        $queueEntry->status = QueueEntry::STATUS_CANCELLED; // saving() hook frees the active slot
        $queueEntry->save();

        AuditLog::record('queue.cancelled', $queueEntry);

        return back()->with('success', "Queue number {$queueEntry->queue_code} was cancelled.");
    }
}
