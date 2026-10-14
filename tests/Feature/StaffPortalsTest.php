<?php

namespace Tests\Feature;

use App\Models\ClearanceItem;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\QueueEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\AcademicTerm;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Session 3: Registration, Cashier and Department workflows + role security.
 *
 * Seed recap: Juan (2024-00001) pending enrollment, clearance 3/5 (CCS thesis + REG
 * records pending), TOR processing (paid), GMC ready, queue A-001 waiting.
 * Ana (2024-00002) has grades only + one pending clearance-fee charge (P100).
 * Leo (2024-00003) approved enrollment (MATH101, ITP101) + a pending COE request.
 */
class StaffPortalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Inertia looks for resources/js/pages (lowercase); this project uses resources/js/Pages.
        // Windows does not care, Linux does, so skip that file-exists check in tests.
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->seed(DatabaseSeeder::class);
    }

    // ---- helpers ----------------------------------------------------------------

    private function user(string $email): User { return User::where('email', $email)->firstOrFail(); }
    private function registrar(): User { return $this->user('registration@school.test'); }
    private function cashier(): User   { return $this->user('cashier@school.test'); }
    private function deptHead(): User  { return $this->user('department@school.test'); }
    private function admin(): User     { return $this->user('admin@school.test'); }

    private function juan(): Student { return Student::findByNumber('2024-00001'); }
    private function ana(): Student  { return Student::findByNumber('2024-00002'); }
    private function leo(): Student  { return Student::findByNumber('2024-00003'); }

    private function term(): array { return AcademicTerm::current(); }
    private function subject(string $code): Subject { return Subject::where('code', $code)->firstOrFail(); }

    private function leosCoe(): DocumentRequest
    {
        return DocumentRequest::where('student_id', $this->leo()->id)->firstOrFail();
    }

    private function gradePayload(array $o = []): array
    {
        return array_merge($this->term(), [
            'student_no' => '2024-00003',
            'subject_id' => $this->subject('ITP101')->id,
            'grade'      => '1.75',
        ], $o);
    }

    private function enrollAna(): Enrollment
    {
        $this->actingAs($this->ana()->user)->post('/student/enrollments', $this->term() + [
            'year_level' => 3,
            'subject_ids' => Subject::whereIn('code', ['NET101', 'ITP101'])->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        return Enrollment::where('student_id', $this->ana()->id)->firstOrFail();
    }

    // ---- ENROLLMENT: student -> registration -> approval -> student ----------------

    public function test_enrollment_workflow_end_to_end(): void
    {
        $enrollment = $this->enrollAna();

        // Registration sees it in the pending list (Juan's seeded one + Ana's).
        $this->actingAs($this->registrar())->get('/registration')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Registration')
                ->where('stats.pending', '2')
                ->has('enrollments.rows', 2)
                ->where('enrollments.rows.0.student_no', '2024-00001'));

        // The review screen shows the selected subjects and the decision buttons.
        $this->get("/registration/enrollments/{$enrollment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('StaffDetail')
                ->has('table.rows', 2)
                ->where('review.actions.0.value', 'approved'));

        $this->patch("/registration/enrollments/{$enrollment->id}", ['status' => 'approved'])
            ->assertRedirect('/registration')->assertSessionHas('success');

        $enrollment->refresh();
        $this->assertSame('approved', $enrollment->status);
        $this->assertSame($this->registrar()->id, $enrollment->reviewed_by);
        $this->assertNotNull($enrollment->reviewed_at);
        $this->assertSame('approved', $enrollment->statusHistories()->get()->last()->to_status);

        // Student sees "Approved".
        $this->actingAs($this->ana()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('stats.enrollment', 'Approved'));

        // It moved to the processed tab.
        $this->actingAs($this->registrar())->get('/registration?tab=processed')
            ->assertInertia(fn (Assert $p) => $p->has('enrollments.rows', 2)); // Leo (seeded) + Ana
    }

    public function test_reject_needs_remarks_and_an_enrollment_is_reviewed_only_once(): void
    {
        $enrollment = Enrollment::where('student_id', $this->juan()->id)->firstOrFail();
        $this->actingAs($this->registrar());

        $this->patch("/registration/enrollments/{$enrollment->id}", ['status' => 'rejected'])
            ->assertSessionHasErrors('remarks');
        $this->patch("/registration/enrollments/{$enrollment->id}", ['status' => 'banana'])
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $enrollment->fresh()->status);

        $this->patch("/registration/enrollments/{$enrollment->id}", ['status' => 'rejected', 'remarks' => 'Missing prerequisite'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', $enrollment->fresh()->status);

        // A second review changes nothing.
        $this->patch("/registration/enrollments/{$enrollment->id}", ['status' => 'approved'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('rejected', $enrollment->fresh()->status);

        // The student sees the remark.
        $this->actingAs($this->juan()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('stats.enrollment', 'Rejected'));
    }

    public function test_enrollment_search_really_filters(): void
    {
        $this->enrollAna();
        $this->actingAs($this->registrar());

        $this->get('/registration?q=Juan')->assertInertia(fn (Assert $p) => $p
            ->has('enrollments.rows', 1)->where('enrollments.rows.0.name', 'Juan Dela Cruz'));
        $this->get('/registration?q=2024-00002')->assertInertia(fn (Assert $p) => $p
            ->has('enrollments.rows', 1)->where('enrollments.rows.0.student_no', '2024-00002'));
        $this->get('/registration?q=nobody-here')->assertInertia(fn (Assert $p) => $p->has('enrollments.rows', 0));
    }

    public function test_registration_cannot_touch_grades(): void
    {
        $this->actingAs($this->registrar())->post('/department/grades', $this->gradePayload())->assertForbidden();
        $this->assertSame(0, Grade::where('subject_id', $this->subject('ITP101')->id)->where('student_id', $this->leo()->id)->count());
    }

    // ---- QUEUE -------------------------------------------------------------------------------

    public function test_queue_workflow_end_to_end(): void
    {
        // Ana joins after Juan (A-001).
        $this->actingAs($this->ana()->user)->post('/student/queue', ['service' => 'enrollment']);
        $ana = QueueEntry::where('student_id', $this->ana()->id)->firstOrFail();

        $this->actingAs($this->registrar())->get('/registration')
            ->assertInertia(fn (Assert $p) => $p->where('stats.in_queue', '2')->has('queue.waiting', 2));

        // Call next -> the lowest number (Juan) is called to Window 1.
        $this->post('/staff/queue/call-next', ['counter' => 'Window 1', 'service' => ''])->assertSessionHas('success');
        $juan = $this->juan()->queueEntries()->first();
        $this->assertSame('serving', $juan->status);
        $this->assertSame('Window 1', $juan->counter);
        $this->assertNull($juan->started_at);
        $this->assertSame('waiting', $ana->fresh()->status);

        // Student sees they were called.
        $this->actingAs($this->juan()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('queue.active.0.status', 'serving')->where('queue.active.0.counter', 'Window 1'));

        // The window is busy until the entry is finished.
        $this->actingAs($this->registrar())
            ->post('/staff/queue/call-next', ['counter' => 'Window 1'])->assertSessionHasErrors('counter');

        // Cannot complete before processing starts; start; then complete.
        $this->patch("/staff/queue/{$juan->id}", ['action' => 'complete'])->assertSessionHas('error');
        $this->patch("/staff/queue/{$juan->id}", ['action' => 'start'])->assertSessionHas('success');
        $this->assertNotNull($juan->fresh()->started_at);
        $this->patch("/staff/queue/{$juan->id}", ['action' => 'complete'])->assertSessionHas('success');
        $this->assertSame('completed', $juan->fresh()->status);
        $this->assertNotNull($juan->fresh()->completed_at);
        $this->assertNull($juan->fresh()->is_active); // the student may queue again

        // Next call goes to Ana; skipping closes her number.
        $this->post('/staff/queue/call-next', ['counter' => 'Window 1'])->assertSessionHas('success');
        $this->assertSame('serving', $ana->fresh()->status);
        $this->patch("/staff/queue/{$ana->id}", ['action' => 'skip'])->assertSessionHas('success');
        $this->assertSame('skipped', $ana->fresh()->status);

        // Nobody left.
        $this->post('/staff/queue/call-next', ['counter' => 'Window 2'])->assertSessionHasErrors('service');
    }

    public function test_queue_can_be_filtered_by_service_and_cancelled(): void
    {
        $this->actingAs($this->ana()->user)->post('/student/queue', ['service' => 'documents']);
        $docEntry = QueueEntry::where('student_id', $this->ana()->id)->firstOrFail();

        $this->actingAs($this->registrar())
            ->post('/staff/queue/call-next', ['counter' => 'Window 2', 'service' => 'documents'])->assertSessionHas('success');
        $this->assertSame('serving', $docEntry->fresh()->status);
        $this->assertSame('waiting', $this->juan()->queueEntries()->first()->status); // Juan wanted enrollment

        $this->patch("/staff/queue/{$docEntry->id}", ['action' => 'cancel'])->assertSessionHas('success');
        $this->assertSame('cancelled', $docEntry->fresh()->status);

        // Finished entries cannot be changed again.
        $this->patch("/staff/queue/{$docEntry->id}", ['action' => 'start'])->assertSessionHas('error');
        // Unknown action / window are refused.
        $this->patch("/staff/queue/{$docEntry->id}", ['action' => 'explode'])->assertSessionHasErrors('action');
        $this->post('/staff/queue/call-next', ['counter' => 'Window 99'])->assertSessionHasErrors('counter');
    }

    public function test_cashier_only_serves_the_payment_queue(): void
    {
        $juansEntry = $this->juan()->queueEntries()->first(); // enrollment service

        $this->actingAs($this->cashier());
        $this->patch("/staff/queue/{$juansEntry->id}", ['action' => 'skip'])->assertForbidden();
        $this->post('/staff/queue/call-next', ['counter' => 'Window 1', 'service' => 'enrollment'])->assertSessionHasErrors('service');
        $this->assertSame('waiting', $juansEntry->fresh()->status);

        $this->actingAs($this->ana()->user)->post('/student/queue', ['service' => 'payment']);
        $this->actingAs($this->cashier())->post('/staff/queue/call-next', ['counter' => 'Window 3'])->assertSessionHas('success');
        $this->assertSame('serving', QueueEntry::where('student_id', $this->ana()->id)->first()->status);

        // And registration cannot serve payment numbers.
        $payEntry = QueueEntry::where('student_id', $this->ana()->id)->first();
        $this->actingAs($this->registrar())->patch("/staff/queue/{$payEntry->id}", ['action' => 'skip'])->assertForbidden();
    }

    // ---- DOCUMENTS + PAYMENT -------------------------------------------------------------------

    public function test_document_with_a_fee_goes_through_cashier_then_registration(): void
    {
        $coe = $this->leosCoe();
        $this->assertSame('pending', $coe->status);

        // Registration sees it and accepts: a fee is due, so it waits for payment.
        $this->actingAs($this->registrar())->get('/documents')
            ->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('is_student', false)->where('staff.can_process', true)
                ->has('staff.rows', 3)); // TOR processing, GMC ready, Leo's COE pending

        $this->get('/documents?q=' . $coe->reference_no)
            ->assertInertia(fn (Assert $p) => $p->has('staff.rows', 1)->where('staff.rows.0.reference_no', $coe->reference_no));

        $this->get("/documents/{$coe->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('StaffDetail')->where('review.actions.0.value', 'processing'));

        $this->patch("/documents/{$coe->id}/status", ['status' => 'processing'])->assertSessionHas('success');
        $this->assertSame('awaiting_payment', $coe->fresh()->status);

        $payment = Payment::where('document_request_id', $coe->id)->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(50.00, (float) $payment->amount);
        $this->assertSame('document_fee', $payment->fee_type);

        // Leo sees "Awaiting payment".
        $this->actingAs($this->leo()->user)->get('/documents')
            ->assertInertia(fn (Assert $p) => $p->where('requests.0.status', 'awaiting_payment'));

        // Cashier sees the pending charge and can find it by reference.
        $this->actingAs($this->cashier())->get('/cashier?q=' . $coe->reference_no)
            ->assertOk()->assertInertia(fn (Assert $p) => $p
                ->has('payments.rows', 1)->where('payments.rows.0.reference', $coe->reference_no)
                ->where('payments.rows.0.can_pay', true));

        // A wrong amount is refused; nothing is recorded.
        $this->post("/cashier/payments/{$payment->id}/pay", ['amount' => '40'])->assertSessionHasErrors('amount');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('awaiting_payment', $coe->fresh()->status);

        // The right amount records it for real.
        $this->post("/cashier/payments/{$payment->id}/pay", ['amount' => '50.00', 'paid_on' => today()->toDateString()])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('OR-0005', $payment->or_number);
        $this->assertSame($this->cashier()->id, $payment->recorded_by);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('processing', $coe->fresh()->status);

        // Paying twice does nothing.
        $this->post("/cashier/payments/{$payment->id}/pay", ['amount' => '50'])->assertSessionHas('error');
        $this->assertSame(1, Payment::where('document_request_id', $coe->id)->where('status', 'paid')->count());

        // Registration finishes it: ready, then released.
        $this->actingAs($this->registrar())->patch("/documents/{$coe->id}/status", ['status' => 'ready'])->assertSessionHas('success');
        $this->assertSame('ready', $coe->fresh()->status);
        $this->actingAs($this->leo()->user)->get('/documents')
            ->assertInertia(fn (Assert $p) => $p->where('requests.0.status_label', 'Ready for release'));

        $this->actingAs($this->registrar())->patch("/documents/{$coe->id}/status", ['status' => 'released'])->assertSessionHas('success');
        $coe->refresh();
        $this->assertSame('released', $coe->status);
        $this->assertSame($this->registrar()->id, $coe->released_by);
        $this->assertNotNull($coe->released_at);

        $this->actingAs($this->leo()->user)->get("/documents/{$coe->id}")
            ->assertInertia(fn (Assert $p) => $p->where('status.value', 'released'));
    }

    public function test_free_documents_skip_payment_and_steps_cannot_be_skipped(): void
    {
        $coe = $this->leosCoe();
        $coe->update(['fee_total' => 0]);
        $this->actingAs($this->registrar());

        // Cannot jump from pending straight to ready / released.
        $this->patch("/documents/{$coe->id}/status", ['status' => 'ready'])->assertSessionHas('error');
        $this->patch("/documents/{$coe->id}/status", ['status' => 'released'])->assertSessionHas('error');
        $this->patch("/documents/{$coe->id}/status", ['status' => 'cancelled'])->assertSessionHasErrors('status');
        $this->assertSame('pending', $coe->fresh()->status);

        $this->patch("/documents/{$coe->id}/status", ['status' => 'processing']);
        $this->assertSame('processing', $coe->fresh()->status);
        $this->assertSame(0, Payment::where('document_request_id', $coe->id)->count());
    }

    public function test_ready_needs_payment_and_reject_rules(): void
    {
        $coe = $this->leosCoe();
        $this->actingAs($this->registrar());

        // Reject needs a reason, then voids the unpaid charge.
        $this->patch("/documents/{$coe->id}/status", ['status' => 'processing']);
        $payment = Payment::where('document_request_id', $coe->id)->firstOrFail();
        $this->patch("/documents/{$coe->id}/status", ['status' => 'rejected'])->assertSessionHasErrors('remarks');
        $this->patch("/documents/{$coe->id}/status", ['status' => 'ready'])->assertSessionHas('error'); // awaiting payment
        $this->patch("/documents/{$coe->id}/status", ['status' => 'rejected', 'remarks' => 'Not eligible'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $coe->fresh()->status);
        $this->assertSame('void', $payment->fresh()->status);

        // A paid request CAN be rejected; the cashier then voids (refunds) the receipt.
        $tor = $this->juan()->documentRequests()->whereHas('documentType', fn ($q) => $q->where('code', 'TOR'))->firstOrFail();
        $this->patch("/documents/{$tor->id}/status", ['status' => 'rejected', 'remarks' => 'x'])->assertSessionHas('success');
        $this->assertSame('rejected', $tor->fresh()->status);
        $receipt = Payment::where('document_request_id', $tor->id)->where('status', 'paid')->firstOrFail();
        $this->actingAs($this->cashier())->post("/cashier/payments/{$receipt->id}/void", ['remarks' => 'Rejected, refunded'])->assertSessionHas('success');
        $this->assertSame('void', $receipt->fresh()->status);
    }

    public function test_student_cancelling_removes_the_pending_charge(): void
    {
        $coe = $this->leosCoe();
        $this->actingAs($this->registrar())->patch("/documents/{$coe->id}/status", ['status' => 'processing']);
        $payment = Payment::where('document_request_id', $coe->id)->firstOrFail();

        $this->actingAs($this->leo()->user)->post("/documents/{$coe->id}/cancel")->assertSessionHas('success');

        $this->assertSame('cancelled', $coe->fresh()->status);
        $this->assertSame('void', $payment->fresh()->status);
        $this->actingAs($this->cashier())->get('/cashier?q=' . $coe->reference_no)
            ->assertInertia(fn (Assert $p) => $p->has('payments.rows', 0));
    }

    public function test_cashier_records_walk_in_payments_and_validates_amounts(): void
    {
        $this->actingAs($this->cashier());
        $ok = ['student_no' => '2024-00001', 'fee_type' => 'tuition', 'amount' => '1500.50'];

        foreach ([
            ['amount' => '0'], ['amount' => '-5'], ['amount' => 'abc'], ['amount' => '10.555'],
            ['amount' => '2000000'], ['amount' => ''],
        ] as $bad) {
            $this->post('/cashier/payments', array_merge($ok, $bad))->assertSessionHasErrors('amount');
        }
        $this->post('/cashier/payments', array_merge($ok, ['student_no' => '9999-99999']))->assertSessionHasErrors('student_no');
        $this->post('/cashier/payments', array_merge($ok, ['fee_type' => 'document_fee']))->assertSessionHasErrors('fee_type');
        $this->post('/cashier/payments', array_merge($ok, ['paid_on' => now()->addDay()->toDateString()]))->assertSessionHasErrors('paid_on');
        $this->post('/cashier/payments', array_merge($ok, ['paid_on' => now()->subDays(90)->toDateString()]))->assertSessionHasErrors('paid_on');
        $this->assertSame(0, Payment::where('amount', '1500.50')->count());

        $before = Payment::where('status', 'paid')->count();
        $this->post('/cashier/payments', array_merge($ok, ['paid_on' => now()->subDays(2)->toDateString()]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $payment = Payment::where('amount', '1500.50')->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->or_number);
        $this->assertSame($this->juan()->id, $payment->student_id);
        $this->assertSame($this->cashier()->id, $payment->recorded_by);
        $this->assertSame(now()->subDays(2)->toDateString(), $payment->paid_at->toDateString());
        $this->assertSame($before + 1, Payment::where('status', 'paid')->count());

        // History tab finds it by OR number and by student.
        $this->get('/cashier?tab=history&q=' . $payment->or_number)
            ->assertInertia(fn (Assert $p) => $p->has('payments.rows', 1)->where('payments.rows.0.status', 'paid'));
        $this->get('/cashier?tab=history&q=Dela')->assertInertia(fn (Assert $p) => $p->where('payments.rows.0.student', 'Juan Dela Cruz'));
    }

    public function test_cashier_stats_come_from_payments_and_duplicates_are_refused(): void
    {
        $this->actingAs($this->cashier())->get('/cashier')
            ->assertInertia(fn (Assert $p) => $p
                ->where('stats.pending', '1')                // Ana's clearance fee
                ->where('stats.receipts', '4')               // 3 seeded walk-ins + Juan's TOR fee
                ->where('stats.collections', '₱ 14,600.00')); // 12,000 + 150 + 2,300 + 150

        // Ana already has a pending clearance fee.
        $this->post('/cashier/payments', ['student_no' => '2024-00002', 'fee_type' => 'clearance_fee', 'amount' => '100'])
            ->assertSessionHasErrors('student_no');
    }

    public function test_voiding_needs_a_reason_and_respects_active_documents(): void
    {
        $this->actingAs($this->cashier());
        $pending = Payment::where('student_id', $this->ana()->id)->where('status', 'pending')->firstOrFail();

        $this->post("/cashier/payments/{$pending->id}/void", [])->assertSessionHasErrors('remarks');
        $this->post("/cashier/payments/{$pending->id}/void", ['remarks' => 'Charged by mistake'])->assertSessionHas('success');
        $this->assertSame('void', $pending->fresh()->status);
        $this->post("/cashier/payments/{$pending->id}/void", ['remarks' => 'again please'])->assertSessionHas('error');
        $this->post("/cashier/payments/{$pending->id}/pay", ['amount' => '100'])->assertSessionHas('error'); // cannot pay a void charge

        // A receipt of a document that is still being processed cannot be voided.
        $docPayment = Payment::where('fee_type', 'document_fee')->whereNotNull('document_request_id')->firstOrFail();
        $this->post("/cashier/payments/{$docPayment->id}/void", ['remarks' => 'try to void'])->assertSessionHas('error');
        $this->assertSame('paid', $docPayment->fresh()->status);
    }

    public function test_document_pages_per_role(): void
    {
        $this->actingAs($this->cashier())->get('/documents')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('staff.can_process', false));
        $this->get('/documents/' . $this->leosCoe()->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('review', null)); // read-only

        $this->actingAs($this->deptHead())->get('/documents')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('staff', null));
        $this->get('/documents/' . $this->leosCoe()->id)->assertForbidden();

        // Search finds a request by reference and by student.
        $coe = $this->leosCoe();
        $this->actingAs($this->registrar())->get('/documents?q=' . $coe->reference_no)
            ->assertInertia(fn (Assert $p) => $p->has('staff.rows', 1));
        $this->get('/documents?tab=all&q=Leo')->assertInertia(fn (Assert $p) => $p->has('staff.rows', 1));
    }

    // ---- CLEARANCE -----------------------------------------------------------------------------------

    public function test_clearance_workflow_end_to_end(): void
    {
        $this->actingAs($this->deptHead())->get('/department')
            ->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component('Department')
                ->where('stats.clearance', '1')
                ->has('clearance.rows', 1)
                ->where('clearance.rows.0.student_no', '2024-00001')
                ->where('clearance.rows.0.requirement', 'Thesis/Capstone submission'));

        $item = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'Thesis/Capstone submission'))
            ->whereHas('clearance', fn ($q) => $q->where('student_id', $this->juan()->id))->firstOrFail();

        $this->patch("/department/clearance-items/{$item->id}", ['status' => 'signed'])->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('signed', $item->status);
        $this->assertSame($this->deptHead()->id, $item->signed_by);
        $this->assertNotNull($item->signed_at);
        $signedAt = $item->signed_at->toDateTimeString();

        // Duplicate signing is refused and the original signature is kept.
        $this->travel(5)->minutes();
        $this->patch("/department/clearance-items/{$item->id}", ['status' => 'signed'])->assertSessionHas('error');
        $this->patch("/department/clearance-items/{$item->id}", ['status' => 'rejected', 'remarks' => 'oops'])->assertSessionHas('error');
        $this->assertSame($signedAt, $item->fresh()->signed_at->toDateTimeString());
        $this->assertSame('signed', $item->fresh()->status);

        // Student sees 2 / 3 signed (Cashier from the demo data + the Department item just signed).
        $this->actingAs($this->juan()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('stats.clearance', '2 / 3 signed'));

        // The signed tab lists it.
        $this->actingAs($this->deptHead())->get('/department?tab=signed')
            ->assertInertia(fn (Assert $p) => $p->has('clearance.rows', 1));
    }

    public function test_rejecting_a_requirement_needs_remarks_and_can_be_signed_later(): void
    {
        $item = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'Thesis/Capstone submission'))->firstOrFail();
        $this->actingAs($this->deptHead());

        $this->patch("/department/clearance-items/{$item->id}", ['status' => 'rejected'])->assertSessionHasErrors('remarks');
        $this->patch("/department/clearance-items/{$item->id}", ['status' => 'rejected', 'remarks' => 'Submit the bound copy'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $item->fresh()->status);

        $this->actingAs($this->juan()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('clearance.items', fn ($items) => collect($items)->contains('remarks', 'Submit the bound copy')));

        $this->actingAs($this->deptHead())->patch("/department/clearance-items/{$item->id}", ['status' => 'signed'])->assertSessionHasNoErrors();
        $this->assertSame('signed', $item->fresh()->status);
    }

    public function test_a_department_only_signs_its_own_requirements(): void
    {
        // "Records verification" belongs to the Registrar's office, not the CCS department.
        $registrarsItem = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'Records verification'))->firstOrFail();

        $this->actingAs($this->deptHead())
            ->patch("/department/clearance-items/{$registrarsItem->id}", ['status' => 'signed'])->assertForbidden();
        $this->assertSame('pending', $registrarsItem->fresh()->status);
    }

    public function test_all_clearance_items_signed_marks_the_clearance_cleared(): void
    {
        $clearance = $this->juan()->clearances()->firstOrFail();
        ClearanceItem::where('clearance_id', $clearance->id)->where('status', 'pending')
            ->where('department_id', '!=', $this->deptHead()->department_id)
            ->update(['status' => 'signed', 'signed_at' => now()]); // the Registrar's office already signed

        $item = ClearanceItem::where('clearance_id', $clearance->id)->where('department_id', $this->deptHead()->department_id)->firstOrFail();
        $this->actingAs($this->deptHead())->patch("/department/clearance-items/{$item->id}", ['status' => 'signed']);

        $this->assertSame('cleared', $clearance->fresh()->status);
        $this->actingAs($this->juan()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('clearance.status', 'cleared'));
    }

    // ---- GRADES -----------------------------------------------------------------------------------------

    public function test_grade_workflow_end_to_end(): void
    {
        $this->actingAs($this->deptHead())->get('/department')
            ->assertInertia(fn (Assert $p) => $p
                ->where('stats.enlisted', '1')               // Leo (approved, has ITP101)
                ->where('stats.to_grade', '1')
                ->where('grading.to_grade.0.student_no', '2024-00003')
                ->where('grading.to_grade.0.code', 'ITP101'));

        $this->post('/department/grades', $this->gradePayload())->assertSessionHasNoErrors()->assertSessionHas('success');

        $grade = Grade::where('student_id', $this->leo()->id)->where('subject_id', $this->subject('ITP101')->id)->firstOrFail();
        $this->assertEquals(1.75, (float) $grade->grade);
        $this->assertSame('passed', $grade->remarks);
        $this->assertSame($this->deptHead()->id, $grade->encoded_by);
        $this->assertNotNull($grade->encoded_at);

        // Leo sees it.
        $this->actingAs($this->leo()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->has('grades', 3)->where('grades.0.code', 'ITP101')->where('grades.0.grade', '1.75'));

        // Updating the same grade changes it (still one row) and leaves the to-grade list empty.
        $this->actingAs($this->deptHead())->post('/department/grades', $this->gradePayload(['grade' => '5.00']))->assertSessionHasNoErrors();
        $this->assertSame(1, Grade::where('student_id', $this->leo()->id)->where('subject_id', $this->subject('ITP101')->id)->count());
        $this->assertEquals(5.00, (float) $grade->fresh()->grade);
        $this->assertSame('failed', $grade->fresh()->remarks);
        $this->get('/department')->assertInertia(fn (Assert $p) => $p
            ->where('stats.to_grade', '0')
            ->where('grading.recent.0.student_no', '2024-00003')   // newest first
            ->where('grading.recent.0.code', 'ITP101'));
    }

    public function test_invalid_grades_are_refused(): void
    {
        $this->actingAs($this->deptHead());

        foreach (['0.5', '0', '5.5', '6', 'abc', '', '1.3', '1.255', '-1'] as $bad) {
            $this->post('/department/grades', $this->gradePayload(['grade' => $bad]))->assertSessionHasErrors('grade');
        }
        $this->post('/department/grades', $this->gradePayload(['student_no' => '9999-99999']))->assertSessionHasErrors('student_no');
        $this->post('/department/grades', $this->gradePayload(['subject_id' => 999999]))->assertSessionHasErrors('subject_id');
        $this->post('/department/grades', $this->gradePayload(['semester' => 'Fourth']))->assertSessionHasErrors('semester');

        $this->assertSame(0, Grade::where('student_id', $this->leo()->id)->where('subject_id', $this->subject('ITP101')->id)->count());
    }

    public function test_a_new_grade_needs_an_approved_enrollment(): void
    {
        $this->actingAs($this->deptHead());

        // Ana never enrolled this term; Juan's enrollment is still pending.
        $this->post('/department/grades', $this->gradePayload(['student_no' => '2024-00002']))->assertSessionHasErrors('student_no');
        $this->post('/department/grades', $this->gradePayload(['student_no' => '2024-00001']))->assertSessionHasErrors('student_no');

        // Once Registration approves Juan, grading works.
        $juansEnrollment = Enrollment::where('student_id', $this->juan()->id)->firstOrFail();
        $this->actingAs($this->registrar())->patch("/registration/enrollments/{$juansEnrollment->id}", ['status' => 'approved']);
        $this->actingAs($this->deptHead())
            ->post('/department/grades', $this->gradePayload(['student_no' => '2024-00001']))->assertSessionHasNoErrors();
        $this->actingAs($this->juan()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('grades.0.code', 'ITP101'));
    }

    public function test_a_department_cannot_grade_other_departments_subjects(): void
    {
        $this->actingAs($this->deptHead());

        // MATH101 / GE101 belong to the College of Arts and Sciences.
        $this->post('/department/grades', $this->gradePayload(['subject_id' => $this->subject('MATH101')->id]))->assertForbidden();
        $this->assertSame(0, Grade::where('student_id', $this->leo()->id)->where('subject_id', $this->subject('MATH101')->id)->count());

        // A CAS head (same role, different department) cannot grade a CCS subject either,
        // and cannot rewrite an existing CCS grade.
        $cas = User::create([
            'name' => 'CAS Head', 'email' => 'cas@school.test', 'password' => 'password', 'role' => 'department',
            'department_id' => $this->subject('GE101')->department_id,
        ]);
        $this->actingAs($cas)->post('/department/grades', $this->gradePayload())->assertForbidden();
        $existing = Grade::where('student_id', $this->leo()->id)->where('subject_id', $this->subject('ITEW3')->id)->firstOrFail();
        $this->post('/department/grades', $this->gradePayload(['subject_id' => $existing->subject_id, 'school_year' => $existing->school_year, 'semester' => $existing->semester, 'grade' => '1.00']))
            ->assertForbidden();
        $this->assertEquals(2.25, (float) $existing->fresh()->grade);

        // ...but the CCS head may correct an existing CCS grade from an earlier term.
        $this->actingAs($this->deptHead())->post('/department/grades', $this->gradePayload([
            'subject_id' => $existing->subject_id, 'school_year' => $existing->school_year, 'semester' => $existing->semester, 'grade' => '2.00',
        ]))->assertSessionHasNoErrors();
        $this->assertEquals(2.00, (float) $existing->fresh()->grade);
    }
    public function test_registration_and_cashier_sign_only_their_own_clearance_items(): void
    {
        $registrarsItem = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'Records verification'))->firstOrFail();
        $cashiersItem   = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'No outstanding balance'))->firstOrFail();
        $ccsItem        = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'Thesis/Capstone submission'))->firstOrFail();

        // The registrar signs the Records verification item, but not the cashier's or CCS's.
        $this->actingAs($this->registrar());
        $this->patch("/department/clearance-items/{$ccsItem->id}", ['status' => 'signed'])->assertForbidden();
        $this->patch("/department/clearance-items/{$cashiersItem->id}", ['status' => 'signed'])->assertForbidden();
        $this->patch("/department/clearance-items/{$registrarsItem->id}", ['status' => 'signed'])->assertSessionHas('success');
        $this->assertSame($this->registrar()->id, $registrarsItem->fresh()->signed_by);

        // The cashier cannot sign the registrar's or CCS's items.
        $this->actingAs($this->cashier());
        $this->patch("/department/clearance-items/{$ccsItem->id}", ['status' => 'signed'])->assertForbidden();
        $this->patch("/department/clearance-items/{$registrarsItem->id}", ['status' => 'signed'])->assertForbidden();
    }
    // ---- ROLE SECURITY MATRIX ---------------------------------------------------------------------------------

    public function test_every_role_is_locked_out_of_the_other_roles_endpoints(): void
    {
        $enrollment = Enrollment::where('student_id', $this->juan()->id)->firstOrFail();
        $item       = ClearanceItem::whereHas('requirement', fn ($q) => $q->where('name', 'Thesis/Capstone submission'))->firstOrFail();
        $payment    = Payment::where('status', 'pending')->firstOrFail();
        $coe        = $this->leosCoe();
        $entry      = $this->juan()->queueEntries()->firstOrFail();

        $endpoints = [
            // [method, url, payload, roles that must get 403]
            ['get',   '/registration', [], ['student', 'cashier', 'department']],
            ['get',   "/registration/enrollments/{$enrollment->id}", [], ['student', 'cashier', 'department']],
            ['patch', "/registration/enrollments/{$enrollment->id}", ['status' => 'approved'], ['student', 'cashier', 'department']],
            ['patch', "/documents/{$coe->id}/status", ['status' => 'processing'], ['student', 'cashier', 'department']],
            ['post',  '/staff/queue/call-next', ['counter' => 'Window 1'], ['student', 'department']],
            ['patch', "/staff/queue/{$entry->id}", ['action' => 'skip'], ['student', 'department', 'cashier']],
            ['get',   '/cashier', [], ['student', 'registration', 'department']],
            ['post',  '/cashier/payments', ['student_no' => '2024-00001', 'fee_type' => 'tuition', 'amount' => '10'], ['student', 'registration', 'department']],
            ['post',  "/cashier/payments/{$payment->id}/pay", ['amount' => '100'], ['student', 'registration', 'department']],
            ['post',  "/cashier/payments/{$payment->id}/void", ['remarks' => 'nope nope'], ['student', 'registration', 'department']],
            ['get',   '/department', [], ['student', 'registration', 'cashier']],
            ['patch', "/department/clearance-items/{$item->id}", ['status' => 'signed'], ['student', 'registration', 'cashier']],
            ['post',  '/department/grades', $this->gradePayload(), ['student', 'registration', 'cashier']],
            ['get',   '/admin', [], ['student', 'registration', 'cashier', 'department']],
        ];

        $users = [
            'student' => $this->juan()->user, 'registration' => $this->registrar(),
            'cashier' => $this->cashier(),    'department' => $this->deptHead(),
        ];

        foreach ($endpoints as [$method, $url, $payload, $blocked]) {
            foreach ($blocked as $role) {
                $this->actingAs($users[$role])->{$method}($url, $payload)
                    ->assertForbidden("{$role} must not be able to {$method} {$url}");
            }
        }

        // Nothing changed behind the 403s.
        $this->assertSame('pending', $enrollment->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $coe->fresh()->status);
        $this->assertSame('waiting', $entry->fresh()->status);
    }

    public function test_guests_cannot_reach_staff_endpoints(): void
    {
        foreach (['/registration', '/cashier', '/department', '/documents'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/cashier/payments', [])->assertRedirect('/login');
        $this->post('/department/grades', [])->assertRedirect('/login');
    }

    public function test_admin_still_passes_every_role_check(): void
    {
        foreach (['/registration', '/cashier', '/department', '/documents'] as $url) {
            $this->actingAs($this->admin())->get($url)->assertOk();
        }
    }
}
