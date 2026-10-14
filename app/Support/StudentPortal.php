<?php

namespace App\Support;

use App\Models\Clearance;
use App\Models\ClearanceItem;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\QueueEntry;
use App\Models\StatusHistory;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Builds everything the Student portal pages display.
 *
 * Every query starts from the logged-in $student (never from an id sent by the
 * browser), so a student can only ever receive their own rows.
 */
class StudentPortal
{
    private const STATUS_LABELS = [
        'awaiting_payment' => 'Awaiting payment',
        'ready'            => 'Ready for release',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    // ---- Pages ---------------------------------------------------------------

    public function dashboard(Student $student): array
    {
        $term        = AcademicTerm::current();
        $enrollments = $this->enrollments($student);
        $grades      = $this->grades($student);
        $clearances  = $this->clearances($student);
        $clearance   = $clearances->first();
        $activeQueue = $this->activeQueue($student);
        $activeDocs  = $this->documentRequests($student)->filter(fn ($d) => $d['active'])->values();

        $currentEnrollment = $enrollments->first(
            fn ($e) => AcademicTerm::isCurrent($e['school_year'], $e['semester'])
        );

        $queueValue = $activeQueue->isEmpty()
            ? 'None'
            : $activeQueue->first()['code'] . ($activeQueue->count() > 1 ? ' +' . ($activeQueue->count() - 1) : '');

        return [
            'student' => $this->studentInfo($student),
            'term'    => $term + ['label' => AcademicTerm::label($term['school_year'], $term['semester'])],
            'stats'   => [
                'queue'      => $queueValue,
                'enrollment' => $currentEnrollment ? $currentEnrollment['status_label'] : 'Not submitted',
                'clearance'  => $clearance ? "{$clearance['signed']} / {$clearance['total']} signed" : 'Not started',
                'gwa'        => $this->gwa($grades),
                'documents'  => (string) $activeDocs->count(),
            ],
            'enrollment' => [
                'terms'              => $this->enrollableTerms($enrollments),
                'subjects'           => $this->subjects($student),
                'default_year_level' => max(1, min(4, (int) $student->year_level)),
                'history'            => $enrollments->values(),
            ],
            'clearance'       => $clearance,
            'can_start_clearance' =>
                $currentEnrollment !== null
                && $currentEnrollment['status'] === 'approved'
                && ! $clearances->contains(
                    fn ($c) => AcademicTerm::isCurrent(
                        $c['school_year'],
                        $c['semester']
                    )
                ),
            'queue' => [
                'services' => collect(QueueEntry::SERVICES)
                    ->map(fn ($s) => ['value' => $s, 'label' => QueueEntry::serviceLabel($s)])->values(),
                'active'   => $activeQueue,
            ],
            'documents' => $activeDocs,
            'grades'    => $grades,
            'activity'  => $this->recentActivity($student),
        ];
    }

    public function history(Student $student): array
    {
        return [
            'student'     => $this->studentInfo($student),
            'enrollments' => $this->enrollments($student)->values(),
            'documents'   => $this->documentRequests($student)->values(),
            'queue'       => $this->queueHistory($student),
            'clearances'  => $this->clearances($student)->map(fn ($c) => [
                'id'          => $c['id'],
                'term_label'  => $c['term_label'],
                'status'      => $c['status'],
                'status_label' => $c['status_label'],
                'progress'    => "{$c['signed']} / {$c['total']} signed",
                'cleared_at'  => $c['cleared_at'],
            ])->values(),
        ];
    }

    // ---- Pieces --------------------------------------------------------------

    public function studentInfo(Student $student): array
    {
        $student->loadMissing('department:id,name');

        return [
            'name'       => $student->user->name,
            'student_no' => $student->student_no,
            'program'    => $student->program,
            'year_level' => $student->year_level,
            'department' => $student->department?->name,
        ];
    }

    public function enrollments(Student $student): Collection
    {
        return $student->enrollments()->with('subjects:id,code,title,units')->get()
            ->sortByDesc(fn ($e) => AcademicTerm::sortKey($e->school_year, $e->semester) . sprintf('-%08d', $e->id))
            ->map(fn (Enrollment $e) => $this->presentEnrollment($e))
            ->values();
    }

    public function presentEnrollment(Enrollment $e): array
    {
        return [
            'id'           => $e->id,
            'school_year'  => $e->school_year,
            'semester'     => $e->semester,
            'term_label'   => AcademicTerm::label($e->school_year, $e->semester),
            'year_level'   => $e->year_level,
            'status'       => $e->status,
            'status_label' => self::statusLabel($e->status),
            'units'        => (int) $e->subjects->sum('units'),
            'subjects'     => $e->subjects->sortBy('code')->map(fn ($s) => [
                'code' => $s->code, 'title' => $s->title, 'units' => $s->units,
            ])->values(),
            'submitted_at' => $e->submitted_at?->format('M d, Y g:i A'),
            'reviewed_at'  => $e->reviewed_at?->format('M d, Y g:i A'),
            'remarks'      => $e->remarks,
            'url'          => route('student.enrollments.show', $e, false),
        ];
    }

    /** The terms in the dropdown, each with the student's existing enrollment for it (if any). */
    private function enrollableTerms(Collection $enrollments): array
    {
        return collect(AcademicTerm::enrollable())->map(function ($t) use ($enrollments) {
            return [
                'value'       => $t['school_year'] . '|' . $t['semester'],
                'school_year' => $t['school_year'],
                'semester'    => $t['semester'],
                'label'       => AcademicTerm::label($t['school_year'], $t['semester']),
                'enrollment'  => $enrollments->first(
                    fn ($e) => $e['school_year'] === $t['school_year'] && $e['semester'] === $t['semester']
                ),
            ];
        })->all();
    }

    /** Subjects the student can pick. Already-passed ones are shown but locked. */
    private function subjects(Student $student): Collection
    {
        $passed = Grade::where('student_id', $student->id)->passed()->pluck('subject_id')->all();

        return Subject::where('is_active', true)->with('department:id,code')->orderBy('code')->get()
            ->map(fn (Subject $s) => [
                'id'         => $s->id,
                'code'       => $s->code,
                'title'      => $s->title,
                'units'      => $s->units,
                'department' => $s->department?->code,
                'passed'     => in_array($s->id, $passed, true),
            ])->values();
    }

    public function grades(Student $student): Collection
    {
        return $student->grades()->with('subject:id,code,title,units')->get()
            ->sortByDesc(fn ($g) => AcademicTerm::sortKey($g->school_year, $g->semester) . '-' . $g->subject?->code)
            ->map(fn (Grade $g) => [
                'id'          => $g->id,
                'code'        => $g->subject?->code,
                'title'       => $g->subject?->title,
                'units'       => $g->subject?->units,
                'grade'       => $g->grade,            // "1.50" or null (not yet graded)
                'remarks'     => $g->remarks ? ucfirst($g->remarks) : null,
                'semester'    => $g->semester,
                'school_year' => $g->school_year,
            ])->values();
    }

    /** General weighted average of every graded subject, or "—". */
    private function gwa(Collection $grades): string
    {
        $graded = $grades->filter(fn ($g) => $g['grade'] !== null && (int) $g['units'] > 0);
        $units  = $graded->sum('units');

        if ($units === 0) {
            return '—';
        }

        $points = $graded->sum(fn ($g) => (float) $g['grade'] * (int) $g['units']);

        return number_format($points / $units, 2);
    }

    /** Newest term first. */
    public function clearances(Student $student): Collection
    {
        return $student->clearances()
            ->with(['items.requirement:id,name,description,sort_order', 'items.department:id,code,name'])
            ->get()
            ->sortByDesc(fn ($c) => AcademicTerm::sortKey($c->school_year, $c->semester))
            ->map(fn (Clearance $c) => $this->presentClearance($c))
            ->values();
    }

    private function presentClearance(Clearance $c): array
    {
        $items = $c->items->sortBy(fn ($i) => $i->requirement?->sort_order)->values();

        return [
            'id'           => $c->id,
            'school_year'  => $c->school_year,
            'semester'     => $c->semester,
            'term_label'   => AcademicTerm::label($c->school_year, $c->semester),
            'status'       => $c->status,
            'status_label' => self::statusLabel($c->status),
            'cleared_at'   => $c->cleared_at?->format('M d, Y'),
            'signed'       => $items->where('status', ClearanceItem::STATUS_SIGNED)->count(),
            'total'        => $items->count(),
            'items'        => $items->map(fn (ClearanceItem $i) => [
                'id'           => $i->id,
                'requirement'  => $i->requirement?->name,
                'description'  => $i->requirement?->description,
                'office'       => $i->department?->name,
                'status'       => $i->status,
                'status_label' => $i->status === ClearanceItem::STATUS_SIGNED ? 'Signed' : self::statusLabel($i->status),
                'signed_at'    => $i->signed_at?->format('M d, Y'),
                'remarks'      => $i->remarks,
            ])->all(),
        ];
    }

    public function activeQueue(Student $student): Collection
    {
        return $student->queueEntries()
            ->whereIn('status', QueueEntry::ACTIVE_STATUSES)
            ->orderBy('queue_number')
            ->get()
            ->map(function (QueueEntry $e) {
                $sameServiceToday = QueueEntry::whereDate('queue_date', $e->queue_date)->where('service', $e->service);
                // The staff-controlled line is the truth: the lowest number staff have called to a window.
                $serving = (clone $sameServiceToday)->where('status', QueueEntry::STATUS_SERVING)
                    ->orderBy('queue_number')->first();

                return [
                    'id'           => $e->id,
                    'code'         => $e->queue_code,
                    'service'      => $e->service,
                    'service_label' => QueueEntry::serviceLabel($e->service),
                    'status'       => $e->status,
                    'status_label' => self::statusLabel($e->status),
                    'counter'      => $e->counter,
                    'ahead'        => (clone $sameServiceToday)->where('status', QueueEntry::STATUS_WAITING)
                                        ->where('queue_number', '<', $e->queue_number)->count(),
                    'now_serving'  => $serving?->queue_code,
                    'now_serving_counter' => $serving?->counter,
                    'can_cancel'   => $e->status === QueueEntry::STATUS_WAITING,
                    'cancel_url'   => route('student.queue.cancel', $e, false),
                ];
            })->values();
    }

    public function queueHistory(Student $student): Collection
    {
        return $student->queueEntries()->orderByDesc('id')->limit(50)->get()
            ->map(fn (QueueEntry $e) => [
                'id'            => $e->id,
                'code'          => $e->queue_code,
                'service_label' => QueueEntry::serviceLabel($e->service),
                'date'          => $e->queue_date?->format('M d, Y'),
                'status'        => $e->status,
                'status_label'  => self::statusLabel($e->status),
            ])->values();
    }

    public function documentRequests(Student $student): Collection
    {
        return $student->documentRequests()->with('documentType:id,code,name', 'payments')
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (DocumentRequest $d) => $this->presentDocumentRequest($d))
            ->values();
    }

    public function presentDocumentRequest(DocumentRequest $d): array
    {
        return [
            'id'           => $d->id,
            'reference_no' => $d->reference_no,
            'document'     => $d->documentType?->name,
            'copies'       => $d->copies,
            'purpose'      => $d->purpose,
            // Set by Registration only. The student can read it but never sends it.
            'expected_release_date' => $d->expected_release_date?->format('M d, Y'),
            'released_at'  => $d->released_at?->format('M d, Y g:i A'),
            'fee_total'    => number_format((float) $d->fee_total, 2),
            'requires_payment' => $d->requiresPayment(),
            'payment'      => $this->documentPayment($d),
            'tracker'      => $this->documentTracker($d),
            'status'       => $d->status,
            'status_label' => self::statusLabel($d->status),
            'requested_at' => $d->created_at?->format('M d, Y'),
            'remarks'      => $d->remarks,
            'active'       => in_array($d->status, DocumentRequest::ACTIVE_STATUSES, true),
            'can_cancel'   => in_array($d->status, DocumentRequest::CANCELLABLE_STATUSES, true),
            'url'          => route('documents.show', $d, false),
            'cancel_url'   => route('documents.cancel', $d, false),
        ];
    }

    public function documentTypes(): Collection
    {
        return DocumentType::where('is_active', true)->orderBy('name')->get()
            ->map(fn (DocumentType $t) => [
                'id'              => $t->id,
                'name'            => $t->name,
                'fee'             => number_format((float) $t->fee, 2),
                'processing_days' => $t->processing_days,
            ])->values();
    }

    /** What the student should read about the fee: a status key + a short label. */
    public function documentPayment(DocumentRequest $d): array
    {
        $paid = $d->payments->firstWhere('status', Payment::STATUS_PAID);

        return match (true) {
            (bool) $paid                                            => ['status' => 'paid', 'label' => "Paid ({$paid->or_number})"],
            ! $d->requiresPayment()                                 => ['status' => 'none', 'label' => 'No fee'],
            $d->status === DocumentRequest::STATUS_AWAITING_PAYMENT => ['status' => 'pending', 'label' => 'Unpaid · pay at the Cashier'],
            in_array($d->status, [DocumentRequest::STATUS_REJECTED, DocumentRequest::STATUS_CANCELLED], true)
                                                                    => ['status' => 'none', 'label' => 'Not charged'],
            default                                                 => ['status' => 'none', 'label' => 'Due after Registration accepts'],
        };
    }

    /** The steps of the request lifecycle (payment is skipped when there is no fee). Null once closed without a result. */
    public function documentTracker(DocumentRequest $d): ?array
    {
        if (in_array($d->status, [DocumentRequest::STATUS_REJECTED, DocumentRequest::STATUS_CANCELLED], true)) {
            return null;
        }

        $steps = [['value' => 'pending', 'label' => 'Submitted']];
        if ($d->requiresPayment()) {
            $steps[] = ['value' => 'awaiting_payment', 'label' => 'Payment'];
        }
        array_push($steps,
            ['value' => 'processing', 'label' => 'Processing'],
            ['value' => 'ready', 'label' => 'Ready for release'],
            ['value' => 'released', 'label' => 'Released'],
        );

        return ['steps' => $steps, 'current' => $d->status];
    }

    /** The latest status changes of this student's own enrollments, documents and clearance items. */
    public function recentActivity(Student $student, int $limit = 6): Collection
    {
        $own = [
            (new Enrollment())->getMorphClass()      => $student->enrollments()->pluck('id'),
            (new DocumentRequest())->getMorphClass() => $student->documentRequests()->pluck('id'),
            (new ClearanceItem())->getMorphClass()   => ClearanceItem::whereIn('clearance_id', $student->clearances()->pluck('id'))->pluck('id'),
        ];

        return StatusHistory::query()
            ->where(function ($q) use ($own) {
                foreach ($own as $type => $ids) {
                    $q->orWhere(fn ($w) => $w->where('trackable_type', $type)->whereIn('trackable_id', $ids));
                }
            })
            ->with('trackable')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (StatusHistory $h) {
                $t = $h->trackable;

                [$what, $detail] = match (true) {
                    $t instanceof Enrollment      => ['Enrollment', AcademicTerm::label($t->school_year, $t->semester)],
                    $t instanceof DocumentRequest => ['Document request', trim(($t->reference_no ?? '') . ' ' . ($t->documentType?->name ?? ''))],
                    $t instanceof ClearanceItem   => ['Clearance', $t->requirement?->name ?? ''],
                    default                       => [null, null],
                };

                return $what === null ? null : [
                    'id'           => $h->id,
                    'what'         => $what,
                    'detail'       => $detail,
                    'status'       => $h->to_status,
                    'status_label' => self::statusLabel($h->to_status),
                    'at'           => $h->created_at?->format('M d, g:i A'),
                ];
            })
            ->filter()
            ->values();
    }

    /** Status timeline for a detail page. */
    public function timeline($model): Collection
    {
        return $model->statusHistories()->get()->map(fn ($h) => [
            'status'       => $h->to_status,
            'status_label' => self::statusLabel($h->to_status),
            'at'           => $h->created_at?->format('M d, Y g:i A'),
            'remarks'      => $h->remarks,
        ])->values();
    }
}
