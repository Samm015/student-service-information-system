<?php

namespace App\Support;

use App\Models\ClearanceItem;
use App\Models\DocumentRequest;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds everything the staff pages display (Registration, Cashier, Department,
 * document processing). Same idea as StudentPortal: controllers stay thin and
 * every number/table on screen comes from the database.
 */
class StaffPortal
{
    public const PER_PAGE = 10;

    private const FEE_LABELS = [
        'tuition'       => 'Tuition',
        'miscellaneous' => 'Miscellaneous',
        'document_fee'  => 'Document fee',
        'clearance_fee' => 'Clearance fee',
    ];

    public static function feeLabel(?string $type): string
    {
        return self::FEE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
    }

    public static function money($value): string
    {
        return number_format((float) $value, 2);
    }

    /** Search text from ?q= (trimmed, max 50 characters). */
    public static function searchText(Request $request, string $key = 'q'): string
    {
        return mb_substr(trim((string) $request->query($key, '')), 0, 50);
    }

    /** One of the allowed values from the query string, else the default. */
    public static function choice(Request $request, string $key, array $allowed, string $default): string
    {
        $value = (string) $request->query($key, $default);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** Previous/next links as relative URLs (they keep search + tab in the query string). */
    public function pager(LengthAwarePaginator $page): array
    {
        $relative = fn (?string $url) => $url ? preg_replace('#^https?://[^/]+#', '', $url) : null;

        return [
            'prev'  => $relative($page->previousPageUrl()),
            'next'  => $relative($page->nextPageUrl()),
            'from'  => $page->firstItem(),
            'to'    => $page->lastItem(),
            'total' => $page->total(),
        ];
    }

    // ---- Queue (Registration + Cashier windows) ----------------------------------------

    public function queuePanel(User $user): array
    {
        QueueEntry::expireStale();

        $services = QueueEntry::servicesFor($user);

        $today = QueueEntry::query()
            ->whereDate('queue_date', today())
            ->whereIn('service', $services)
            ->with('student.user:id,name')
            ->orderBy('queue_number')
            ->get();

        $current = $today->where('status', QueueEntry::STATUS_SERVING)->values();

        return [
            'services' => collect([['value' => '', 'label' => 'Any service']])
                ->concat(collect($services)->map(fn ($s) => ['value' => $s, 'label' => QueueEntry::serviceLabel($s)]))
                ->values(),
            'counters'    => array_values(config('school.counters', [])),
            'call_url'    => route('staff.queue.call', [], false),
            'now_serving' => $current->map(fn ($e) => $this->presentQueueEntry($e))->values(),
            'waiting'     => $today->where('status', QueueEntry::STATUS_WAITING)
                                ->map(fn ($e) => $this->presentQueueEntry($e))->values(),
            'recent'      => $today->whereIn('status', [QueueEntry::STATUS_COMPLETED, QueueEntry::STATUS_SKIPPED, QueueEntry::STATUS_CANCELLED])
                                ->sortByDesc('id')->take(8)
                                ->map(fn ($e) => $this->presentQueueEntry($e))->values(),
            'stats' => [
                'waiting'     => $today->where('status', QueueEntry::STATUS_WAITING)->count(),
                'now_serving' => $current->isEmpty() ? '—' : $current->pluck('queue_code')->implode(', '),
            ],
        ];
    }

    public function presentQueueEntry(QueueEntry $e): array
    {
        $stage = match (true) {
            $e->status === QueueEntry::STATUS_WAITING => 'waiting',
            $e->isCalled()                            => 'called',
            $e->status === QueueEntry::STATUS_SERVING => 'processing',
            default                                   => $e->status,
        };

        $actions = match ($stage) {
            'waiting'    => ['skip', 'cancel'],
            'called'     => ['start', 'skip', 'cancel'],
            'processing' => ['complete', 'cancel'],
            default      => [],
        };

        return [
            'id'            => $e->id,
            'code'          => $e->queue_code,
            'student'       => $e->student?->user?->name,
            'student_no'    => $e->student?->student_no,
            'service'       => $e->service,
            'service_label' => QueueEntry::serviceLabel($e->service),
            'status'        => $stage === 'called' ? 'serving' : $stage,
            'status_label'  => ucfirst($stage),
            'counter'       => $e->counter,
            'time'          => $e->created_at?->format('g:i A'),
            'actions'       => $actions,
            'update_url'    => route('staff.queue.update', $e, false),
        ];
    }

    // ---- Registration: enrollments --------------------------------------------------------

    public function enrollmentList(Request $request): array
    {
        $tab = self::choice($request, 'tab', ['pending', 'processed', 'all'], 'pending');
        $q   = self::searchText($request);

        $page = Enrollment::query()
            ->with(['student.user:id,name', 'subjects:id,units'])
            // Pending: first come, first served. Processed: newest decision first. All: newest first.
            ->when($tab === 'pending', fn ($x) => $x->where('status', Enrollment::STATUS_PENDING)->orderBy('submitted_at')->orderBy('id'))
            ->when($tab === 'processed', fn ($x) => $x->whereIn('status', [Enrollment::STATUS_APPROVED, Enrollment::STATUS_REJECTED])->orderByDesc('reviewed_at')->orderByDesc('id'))
            ->when($tab === 'all', fn ($x) => $x->orderByDesc('id'))
            ->when($q !== '', fn ($x) => $x->whereHas('student', fn ($s) => $s
                ->where('student_no', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => ['tab' => $tab, 'q' => $q],
            'rows'    => $page->getCollection()->map(fn (Enrollment $e) => [
                'id'           => $e->id,
                'student_no'   => $e->student?->student_no,
                'name'         => $e->student?->user?->name,
                'program'      => $e->student?->program,
                'term_label'   => AcademicTerm::label($e->school_year, $e->semester),
                'units'        => (int) $e->subjects->sum('units'),
                'status'       => $e->status,
                'status_label' => StudentPortal::statusLabel($e->status),
                'submitted_at' => $e->submitted_at?->format('M d, Y'),
                'url'          => route('registration.enrollments.show', $e, false),
            ])->values(),
            'pager' => $this->pager($page),
        ];
    }

    // ---- Document requests (staff view) -----------------------------------------------------

    public function documentList(Request $request): array
    {
        $tab = self::choice($request, 'tab', ['active', 'closed', 'all'], 'active');
        $q   = self::searchText($request);

        $page = DocumentRequest::query()
            ->with(['student.user:id,name', 'documentType:id,name', 'payments'])
            ->when($tab === 'active', fn ($x) => $x->whereIn('status', DocumentRequest::ACTIVE_STATUSES)->orderBy('id'))
            ->when($tab === 'closed', fn ($x) => $x->whereNotIn('status', DocumentRequest::ACTIVE_STATUSES)->orderByDesc('id'))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('reference_no', 'like', "%{$q}%")
                ->orWhereHas('student', fn ($s) => $s
                    ->where('student_no', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")))))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => ['tab' => $tab, 'q' => $q],
            'rows'    => $page->getCollection()->map(fn (DocumentRequest $d) => $this->documentRow($d))->values(),
            'pager' => $this->pager($page),
        ];
    }

    /** One document request as a table row (used by the full list and the Registration dashboard). */
    public function documentRow(DocumentRequest $d): array
    {
        return [
            'id'           => $d->id,
            'reference_no' => $d->reference_no,
            'student'      => $d->student?->user?->name,
            'student_no'   => $d->student?->student_no,
            'document'     => $d->documentType?->name,
            'copies'       => $d->copies,
            'fee'          => self::money($d->fee_total),
            'payment'      => $this->paymentSummary($d),
            'status'       => $d->status,
            'status_label' => StudentPortal::statusLabel($d->status),
            'requested_at' => $d->created_at?->format('M d, Y'),
            'expected_release' => $d->expected_release_date?->format('M d, Y'),
            'url'          => route('documents.show', $d, false),
        ];
    }

    /** The oldest open requests Registration still has to act on (dashboard work area). */
    public function documentsToProcess(int $limit = 6): array
    {
        $open = DocumentRequest::query()->whereIn('status', [
            DocumentRequest::STATUS_PENDING, DocumentRequest::STATUS_PROCESSING, DocumentRequest::STATUS_READY,
        ]);

        return [
            'total' => (clone $open)->count(),
            'rows'  => $open->with(['student.user:id,name', 'documentType:id,name', 'payments'])
                ->orderBy('id')->limit($limit)->get()
                ->map(fn (DocumentRequest $d) => $this->documentRow($d))->values(),
        ];
    }

    /** "No fee", "Unpaid" or "Paid (OR-0004)". Works on a loaded `payments` relation. */
    public function paymentSummary(DocumentRequest $d): string
    {
        $paid = $d->payments->firstWhere('status', Payment::STATUS_PAID);

        if ($paid) {
            return "Paid ({$paid->or_number})";
        }

        return $d->requiresPayment() ? 'Unpaid' : 'No fee';
    }

    // ---- Cashier ---------------------------------------------------------------------------------

    public function cashierStats(): array
    {
        $paidToday = Payment::where('status', Payment::STATUS_PAID)->where('paid_at', '>=', today());

        return [
            'collections' => '₱ ' . self::money((clone $paidToday)->sum('amount')),
            'pending'     => (string) Payment::where('status', Payment::STATUS_PENDING)->count(),
            'receipts'    => (string) (clone $paidToday)->count(),
        ];
    }

    public function paymentList(Request $request): array
    {
        $tab = self::choice($request, 'tab', ['pending', 'history'], 'pending');
        $q   = self::searchText($request);

        $page = Payment::query()
            ->with(['student.user:id,name', 'documentRequest:id,reference_no,status', 'cashier:id,name'])
            ->when($tab === 'pending', fn ($x) => $x->where('status', Payment::STATUS_PENDING)->orderBy('id'))
            ->when($tab === 'history', fn ($x) => $x->whereIn('status', [Payment::STATUS_PAID, Payment::STATUS_VOID])->orderByDesc('id'))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('or_number', 'like', "%{$q}%")
                ->orWhereHas('documentRequest', fn ($d) => $d->where('reference_no', 'like', "%{$q}%"))
                ->orWhereHas('student', fn ($s) => $s
                    ->where('student_no', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")))))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => ['tab' => $tab, 'q' => $q],
            'rows'    => $page->getCollection()->map(fn (Payment $p) => [
                'id'           => $p->id,
                'student'      => $p->student?->user?->name,
                'student_no'   => $p->student?->student_no,
                'fee_label'    => self::feeLabel($p->fee_type),
                'reference'    => $p->documentRequest?->reference_no ?? '—',
                'amount'       => self::money($p->amount),
                'amount_raw'   => number_format((float) $p->amount, 2, '.', ''),
                'status'       => $p->status,
                'status_label' => ucfirst($p->status),
                'or_number'    => $p->or_number ?? '—',
                'date'         => ($p->paid_at ?? $p->created_at)?->format('M d, Y g:i A'),
                'cashier'      => $p->cashier?->name,
                'remarks'      => $p->remarks,
                'can_pay'      => $p->status === Payment::STATUS_PENDING,
                'can_void'     => $p->canBeVoided(),
                'pay_url'      => route('cashier.payments.pay', $p, false),
                'void_url'     => route('cashier.payments.void', $p, false),
            ])->values(),
            'pager' => $this->pager($page),
        ];
    }

    // ---- Department ----------------------------------------------------------------------------------

    public function clearanceList(Request $request, int $departmentId): array
    {
        $tab = self::choice($request, 'tab', ['open', 'signed', 'all'], 'open');
        $q   = self::searchText($request);

        $page = ClearanceItem::query()
            ->where('department_id', $departmentId)
            ->with(['clearance.student.user:id,name', 'requirement:id,name'])
            ->when($tab === 'open', fn ($x) => $x->whereIn('status', [ClearanceItem::STATUS_PENDING, ClearanceItem::STATUS_REJECTED])->orderBy('id'))
            ->when($tab === 'signed', fn ($x) => $x->where('status', ClearanceItem::STATUS_SIGNED)->orderByDesc('signed_at'))
            ->when($q !== '', fn ($x) => $x->whereHas('clearance.student', fn ($s) => $s
                ->where('student_no', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => ['tab' => $tab, 'q' => $q],
            'rows'    => $page->getCollection()->map(fn (ClearanceItem $i) => [
                'id'           => $i->id,
                'student_no'   => $i->clearance?->student?->student_no,
                'student'      => $i->clearance?->student?->user?->name,
                'requirement'  => $i->requirement?->name,
                'term_label'   => $i->clearance ? AcademicTerm::label($i->clearance->school_year, $i->clearance->semester) : null,
                'status'       => $i->status,
                'status_label' => $i->status === ClearanceItem::STATUS_SIGNED ? 'Signed' : StudentPortal::statusLabel($i->status),
                'signed_at'    => $i->signed_at?->format('M d, Y g:i A'),
                'remarks'      => $i->remarks,
                'can_sign'     => $i->status !== ClearanceItem::STATUS_SIGNED,
                'can_reject'   => $i->status === ClearanceItem::STATUS_PENDING,
                'update_url'   => route('department.clearance.update', $i, false),
            ])->values(),
            'pager' => $this->pager($page),
        ];
    }

    public function openClearanceCount(int $departmentId): int
    {
        return ClearanceItem::where('department_id', $departmentId)
            ->whereIn('status', [ClearanceItem::STATUS_PENDING, ClearanceItem::STATUS_REJECTED])->count();
    }

    /**
     * Approved enrollment lines (student x subject) for subjects this department owns,
     * in the given term, that still have no grade. This is the department's "to grade" list.
     */
    public function gradingQuery(int $departmentId, array $term)
    {
        return DB::table('enrollment_subject as es')
            ->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
            ->join('subjects as s', 's.id', '=', 'es.subject_id')
            ->join('students as st', 'st.id', '=', 'e.student_id')
            ->join('users as u', 'u.id', '=', 'st.user_id')
            ->where('e.status', Enrollment::STATUS_APPROVED)
            ->where('e.school_year', $term['school_year'])
            ->where('e.semester', $term['semester'])
            ->where('s.department_id', $departmentId)
            ->whereNotExists(fn ($g) => $g->select(DB::raw(1))->from('grades as g')
                ->whereColumn('g.student_id', 'e.student_id')
                ->whereColumn('g.subject_id', 'es.subject_id')
                ->whereColumn('g.school_year', 'e.school_year')
                ->whereColumn('g.semester', 'e.semester')
                ->whereNotNull('g.grade'));
    }

    public function enlistedCount(int $departmentId, array $term): int
    {
        return Enrollment::where('status', Enrollment::STATUS_APPROVED)
            ->where('school_year', $term['school_year'])->where('semester', $term['semester'])
            ->whereHas('subjects', fn ($s) => $s->where('subjects.department_id', $departmentId))
            ->count();
    }

    /** Terms offered in the grade form: the current one plus any term that already has grades/enrollments. */
    public function gradeTerms(): Collection
    {
        $current = AcademicTerm::current();

        return collect([$current])
            ->concat(Grade::query()->select('school_year', 'semester')->distinct()->get()->map->only(['school_year', 'semester']))
            ->concat(Enrollment::where('status', Enrollment::STATUS_APPROVED)->select('school_year', 'semester')->distinct()->get()->map->only(['school_year', 'semester']))
            ->unique(fn ($t) => $t['school_year'] . '|' . $t['semester'])
            ->sortByDesc(fn ($t) => AcademicTerm::sortKey($t['school_year'], $t['semester']))
            ->map(fn ($t) => [
                'value' => $t['school_year'] . '|' . $t['semester'],
                'label' => AcademicTerm::label($t['school_year'], $t['semester']),
            ])->values();
    }
}
