<?php

namespace App\Http\Controllers;

use App\Http\Requests\CallNextQueueRequest;
use App\Http\Requests\UpdateQueueEntryRequest;
use App\Models\AuditLog;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Window controls shared by Registration (every service except payment) and the
 * Cashier (payment). What a window may serve comes from QueueEntry::servicesFor().
 *
 * Life of an entry:  waiting -> serving (called, started_at empty)
 *                           -> serving + started_at (being processed) -> completed
 *                    or skipped (no-show) / cancelled at any point before completion.
 */
class StaffQueueController extends Controller
{
    public function call(CallNextQueueRequest $request)
    {
        $user    = $request->user();
        $counter = $request->validated('counter');
        $service = $request->validated('service') ?: null;
        $allowed = QueueEntry::servicesFor($user);

        $entry = DB::transaction(function () use ($user, $counter, $service, $allowed) {
            QueueEntry::expireStale();

            // One person at a time per window.
            $busy = QueueEntry::whereDate('queue_date', today())
                ->where('status', QueueEntry::STATUS_SERVING)
                ->where('counter', $counter)
                ->first();

            if ($busy) {
                throw ValidationException::withMessages([
                    'counter' => "{$counter} is still serving {$busy->queue_code}. Complete or skip that number first.",
                ]);
            }

            $next = QueueEntry::whereDate('queue_date', today())
                ->where('status', QueueEntry::STATUS_WAITING)
                ->whereIn('service', $allowed)
                ->when($service, fn ($q) => $q->where('service', $service))
                ->orderBy('queue_number')
                ->lockForUpdate()
                ->first();

            if (! $next) {
                throw ValidationException::withMessages(['service' => 'Nobody is waiting for this service.']);
            }

            $next->forceFill([
                'status'     => QueueEntry::STATUS_SERVING,
                'counter'    => $counter,
                'served_by'  => $user->id,
                'started_at' => null,
            ])->save();

            return $next;
        });

        AuditLog::record('queue.called', $entry, null, ['counter' => $counter]);

        return back()->with('success', "Called {$entry->queue_code} to {$counter}.");
    }

    public function update(UpdateQueueEntryRequest $request, QueueEntry $queueEntry)
    {
        $action = $request->validated('action');
        $user   = $request->user();

        $result = DB::transaction(function () use ($queueEntry, $action, $user) {
            QueueEntry::expireStale();

            $entry = QueueEntry::whereKey($queueEntry->id)->lockForUpdate()->firstOrFail();

            $waiting    = $entry->status === QueueEntry::STATUS_WAITING;
            $called     = $entry->isCalled();
            $processing = $entry->status === QueueEntry::STATUS_SERVING && $entry->started_at !== null;

            $allowed = match ($action) {
                'start'    => $called,
                'complete' => $processing,
                'skip'     => $waiting || $called,
                'cancel'   => $waiting || $called || $processing,
            };

            if (! $allowed) {
                return ['error' => "{$entry->queue_code} is {$entry->status}, so it cannot be changed that way."];
            }

            match ($action) {
                'start'    => $entry->forceFill(['started_at' => now(), 'served_by' => $user->id]),
                'complete' => $entry->forceFill(['status' => QueueEntry::STATUS_COMPLETED, 'completed_at' => now()]),
                'skip'     => $entry->forceFill(['status' => QueueEntry::STATUS_SKIPPED, 'completed_at' => now()]),
                'cancel'   => $entry->forceFill(['status' => QueueEntry::STATUS_CANCELLED, 'completed_at' => now()]),
            };
            $entry->save(); // the saving() hook frees the student's "active" slot when finished

            return ['entry' => $entry];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        $entry = $result['entry'];
        AuditLog::record("queue.{$action}", $entry);

        $message = match ($action) {
            'start'    => "{$entry->queue_code} is now being processed.",
            'complete' => "{$entry->queue_code} completed.",
            'skip'     => "{$entry->queue_code} was skipped.",
            'cancel'   => "{$entry->queue_code} was cancelled.",
        };

        return back()->with('success', $message);
    }
}
