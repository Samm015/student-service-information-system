<?php

namespace Tests\Feature;

use App\Models\Clearance;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Enrollment;
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
 * Student portal workflow + security tests.
 * Seed data: Juan (2024-00001) has a pending enrollment, 3 grades, 3/5 clearance,
 * 2 document requests (TOR processing, GMC ready) and queue A-001.
 * Ana (2024-00002) has 2 passed grades and nothing else; Leo has an approved enrollment.
 */
class StudentPortalTest extends TestCase
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

    private function student(string $no): Student
    {
        return Student::findByNumber($no);
    }

    private function juan(): Student { return $this->student('2024-00001'); }
    private function ana(): Student  { return $this->student('2024-00002'); }
    private function leo(): Student  { return $this->student('2024-00003'); }

    private function term(): array { return AcademicTerm::current(); }

    private function subjectIds(string ...$codes): array
    {
        return Subject::whereIn('code', $codes)->pluck('id')->all();
    }

    private function enrollPayload(array $overrides = []): array
    {
        return array_merge($this->term(), [
            'year_level'  => 3,
            'subject_ids' => $this->subjectIds('NET101', 'ITP101', 'GE101'),
        ], $overrides);
    }

    // ---- Dashboard / grades / clearance (own data only) ------------------------

    public function test_dashboard_shows_only_the_logged_in_students_data(): void
    {
        $this->actingAs($this->juan()->user)->get('/student')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Student')
                ->where('student.student_no', '2024-00001')
                ->where('stats.enrollment', 'Pending')
                ->where('stats.clearance', '1 / 3 signed')
                ->where('stats.gwa', '1.75')
                ->where('stats.queue', 'A-001')
                ->where('stats.documents', '2')
                ->has('grades', 3)
                ->has('documents', 2)
                ->has('queue.active', 1));

        // Ana has 2 grades, no clearance, no enrollment, no documents, no queue.
        $this->actingAs($this->ana()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p
                ->has('grades', 2)
                ->where('stats.enrollment', 'Not submitted')
                ->where('stats.clearance', 'Not started')
                ->where('stats.queue', 'None')
                ->where('clearance', null)
                ->has('documents', 0));
    }

    public function test_students_cannot_edit_grades(): void
    {
        $grade = $this->juan()->grades()->first();

        $this->assertFalse($this->juan()->user->can('update', $grade));
        $this->actingAs($this->juan()->user)->put("/student/grades/{$grade->id}", ['grade' => 1.0])->assertStatus(404);
    }

    
    public function test_student_can_start_clearance_once_and_cannot_sign_it(): void
    {
        // Arrange: give Ana an approved enrollment for the current term.
        $registrar = User::where(
            'email',
            'registration@school.test'
        )->firstOrFail();

        $enrollment = Enrollment::create($this->term() + [
            'student_id' => $this->ana()->id,
            'year_level' => 3,
            'submitted_at' => now(),
        ]);

        $enrollment->subjects()->attach(
            $this->subjectIds('NET101', 'ITP101', 'GE101')
        );

        $enrollment->changeStatus(
            Enrollment::STATUS_APPROVED,
            $registrar
        );

        // Act: Ana is now eligible to start clearance.
        $this->actingAs($this->ana()->user)
            ->post('/student/clearance')
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('success');

        $clearance = Clearance::where(
            'student_id',
            $this->ana()->id
        )->first();

        $this->assertNotNull($clearance);
        $this->assertSame(
            $this->term()['school_year'],
            $clearance->school_year
        );
        $this->assertSame(
            3,
            $clearance->items()->count()
        );
        $this->assertSame(
            0,
            $clearance->items()->where('status', 'signed')->count()
        );

        // A second submission must not create another clearance.
        $this->post('/student/clearance')
            ->assertSessionHas('error');

        $this->assertSame(
            1,
            Clearance::where('student_id', $this->ana()->id)->count()
        );

        $this->get('/student')->assertInertia(fn (Assert $p) => $p
            ->where('clearance.total', 3)
            ->where('clearance.signed', 0)
            ->where('stats.clearance', '0 / 3 signed'));

        // The student cannot sign a clearance item.
        $item = $clearance->items()
            ->with('requirement')
            ->get()
            ->firstWhere('requirement.name', 'Records verification');

        $this->assertNotNull($item);
        $this->assertFalse($this->ana()->user->can('sign', $item));

        // The Registrar can sign the requirement assigned to its office.
        $this->assertTrue($registrar->can('sign', $item));
    }


    public function test_clearance_is_private_to_its_owner(): void
    {
        // Juan's clearance never appears on Ana's dashboard.
        $this->actingAs($this->ana()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('clearance', null));
        $this->assertSame(1, Clearance::where('student_id', $this->juan()->id)->count());
    }

    // ---- Enrollment --------------------------------------------------------------

    public function test_student_can_submit_an_enrollment_and_see_its_status(): void
    {
        $this->actingAs($this->ana()->user)
            ->post('/student/enrollments', $this->enrollPayload())
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('success');

        $enrollment = Enrollment::where('student_id', $this->ana()->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame('pending', $enrollment->status);
        $this->assertSame(3, $enrollment->subjects()->count());
        $this->assertSame(1, $enrollment->statusHistories()->count());

        $this->get('/student')->assertInertia(fn (Assert $p) => $p
            ->where('stats.enrollment', 'Pending')->has('enrollment.history', 1));
    }

    public function test_student_id_sent_by_the_browser_is_ignored(): void
    {
        $this->actingAs($this->ana()->user)
            ->post('/student/enrollments', $this->enrollPayload(['student_id' => $this->leo()->id]))
            ->assertSessionHasNoErrors();

        // The request belongs to Ana (the session), and Leo's own enrollment is untouched.
        $this->assertSame(1, Enrollment::where('student_id', $this->ana()->id)->count());
        $this->assertSame(1, Enrollment::where('student_id', $this->leo()->id)->count());
    }

    public function test_duplicate_pending_and_approved_enrollments_are_refused(): void
    {
        // Juan already has a pending request for the current term.
        $this->actingAs($this->juan()->user)
            ->post('/student/enrollments', $this->enrollPayload())
            ->assertSessionHasErrors('semester');
        $this->assertSame(1, Enrollment::where('student_id', $this->juan()->id)->count());

        // Leo's is already approved.
        $this->actingAs($this->leo()->user)
            ->post('/student/enrollments', $this->enrollPayload())
            ->assertSessionHasErrors('semester');
        $this->assertSame('approved', Enrollment::where('student_id', $this->leo()->id)->first()->status);
    }

    public function test_invalid_subjects_terms_and_passed_subjects_are_refused(): void
    {
        $this->actingAs($this->ana()->user);

        $this->post('/student/enrollments', $this->enrollPayload(['subject_ids' => [999999]]))
            ->assertSessionHasErrors('subject_ids.0');

        $this->post('/student/enrollments', $this->enrollPayload(['subject_ids' => []]))
            ->assertSessionHasErrors('subject_ids');

        $this->post('/student/enrollments', $this->enrollPayload(['school_year' => '2030-2031']))
            ->assertSessionHasErrors('semester');

        // Ana passed ITEW3.
        $this->post('/student/enrollments', $this->enrollPayload(['subject_ids' => $this->subjectIds('ITEW3', 'GE101')]))
            ->assertSessionHasErrors('subject_ids');

        Subject::where('code', 'NET101')->update(['is_active' => false]);
        $this->post('/student/enrollments', $this->enrollPayload(['subject_ids' => $this->subjectIds('NET101')]))
            ->assertSessionHasErrors('subject_ids.0');

        $this->assertSame(0, Enrollment::where('student_id', $this->ana()->id)->count());
    }

    public function test_rejected_enrollment_can_be_resubmitted_on_the_same_row(): void
    {
        $rejected = Enrollment::create($this->term() + [
            'student_id' => $this->ana()->id, 'year_level' => 3, 'submitted_at' => now(),
        ]);
        $rejected->changeStatus('rejected', null, 'Incomplete subjects');

        $this->actingAs($this->ana()->user)
            ->post('/student/enrollments', $this->enrollPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Enrollment::where('student_id', $this->ana()->id)->count());
        $this->assertSame('pending', $rejected->fresh()->status);
        $this->assertSame(3, $rejected->fresh()->subjects()->count());
    }

    public function test_enrollment_detail_is_private(): void
    {
        $juansEnrollment = Enrollment::where('student_id', $this->juan()->id)->first();

        $this->actingAs($this->ana()->user)->get("/student/enrollments/{$juansEnrollment->id}")->assertForbidden();

        $this->actingAs($this->juan()->user)->get("/student/enrollments/{$juansEnrollment->id}")
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('RequestDetail'));
    }

    // ---- Document requests ----------------------------------------------------------

    public function test_student_can_request_a_document_and_gets_a_reference_number(): void
    {
        $tor = DocumentType::where('code', 'TOR')->first();

        $this->actingAs($this->ana()->user)
            ->post('/documents', ['document_type_id' => $tor->id, 'copies' => 2, 'purpose' => 'Employment'])
            ->assertRedirect(route('documents.index'))
            ->assertSessionHas('success');

        $request = DocumentRequest::where('student_id', $this->ana()->id)->first();
        $this->assertNotNull($request);
        $this->assertMatchesRegularExpression('/^DR-\d{4}$/', $request->reference_no);
        $this->assertSame('pending', $request->status);
        $this->assertEquals(300.00, (float) $request->fee_total); // 150 x 2, computed on the server
        $this->assertSame(1, $request->statusHistories()->count());

        $this->get('/documents')->assertInertia(fn (Assert $p) => $p
            ->component('Documents')->where('is_student', true)->has('requests', 1)
            ->where('requests.0.reference_no', $request->reference_no));
    }

    public function test_document_request_validation_and_duplicates(): void
    {
        $tor = DocumentType::where('code', 'TOR')->first();
        $this->actingAs($this->ana()->user);

        $this->post('/documents', ['document_type_id' => 999999, 'copies' => 1, 'purpose' => 'Work'])
            ->assertSessionHasErrors('document_type_id');
        $this->post('/documents', ['document_type_id' => $tor->id, 'copies' => 0, 'purpose' => 'Work'])
            ->assertSessionHasErrors('copies');
        $this->post('/documents', ['document_type_id' => $tor->id, 'copies' => 11, 'purpose' => 'Work'])
            ->assertSessionHasErrors('copies');
        $this->post('/documents', ['document_type_id' => $tor->id, 'copies' => 1, 'purpose' => ''])
            ->assertSessionHasErrors('purpose');
        $this->assertSame(0, DocumentRequest::where('student_id', $this->ana()->id)->count());

        $ok = ['document_type_id' => $tor->id, 'copies' => 1, 'purpose' => 'Work'];
        $this->post('/documents', $ok)->assertSessionHasNoErrors();
        $this->post('/documents', $ok)->assertSessionHasErrors('document_type_id'); // duplicate active request
        $this->assertSame(1, DocumentRequest::where('student_id', $this->ana()->id)->count());

        // Juan's TOR is already processing, so he cannot request another one either.
        $this->actingAs($this->juan()->user)->post('/documents', $ok)->assertSessionHasErrors('document_type_id');
    }

    public function test_document_requests_are_private_and_only_cancellable_by_the_owner(): void
    {
        $juansRequest = $this->juan()->documentRequests()->first();

        $this->actingAs($this->ana()->user);
        $this->get("/documents/{$juansRequest->id}")->assertForbidden();
        $this->post("/documents/{$juansRequest->id}/cancel")->assertStatus(403);

        // Ana cancels her own pending request, then may request the document again.
        $tor = DocumentType::where('code', 'TOR')->first();
        $this->post('/documents', ['document_type_id' => $tor->id, 'copies' => 1, 'purpose' => 'Work']);
        $mine = $this->ana()->documentRequests()->first();

        $this->get("/documents/{$mine->id}")->assertOk();
        $this->post("/documents/{$mine->id}/cancel")->assertRedirect(route('documents.index'));
        $this->assertSame('cancelled', $mine->fresh()->status);

        $this->post('/documents', ['document_type_id' => $tor->id, 'copies' => 1, 'purpose' => 'Work'])->assertSessionHasNoErrors();
    }

    public function test_staff_cannot_submit_document_requests(): void
    {
        $tor = DocumentType::where('code', 'TOR')->first();
        $cashier = User::where('email', 'cashier@school.test')->first();

        $this->actingAs($cashier)->post('/documents', ['document_type_id' => $tor->id, 'copies' => 1, 'purpose' => 'x'])->assertForbidden();
        $this->get('/documents')->assertOk()->assertInertia(fn (Assert $p) => $p->where('is_student', false));
    }

    // ---- Queue ---------------------------------------------------------------------------

    public function test_student_can_join_see_and_cancel_a_queue_entry(): void
    {
        $this->actingAs($this->ana()->user);

        $this->post('/student/queue', ['service' => 'enrollment'])
            ->assertRedirect(route('student.dashboard'))->assertSessionHas('success');

        $entry = QueueEntry::where('student_id', $this->ana()->id)->first();
        $this->assertMatchesRegularExpression('/^A-\d{3}$/', $entry->queue_code);
        $this->assertSame('waiting', $entry->status);

        $this->get('/student')->assertInertia(fn (Assert $p) => $p
            ->where('queue.active.0.code', $entry->queue_code)->where('stats.queue', $entry->queue_code));

        // Same service again is refused; a different service is fine.
        $this->post('/student/queue', ['service' => 'enrollment'])->assertSessionHasErrors('service');
        $this->post('/student/queue', ['service' => 'documents'])->assertSessionHasNoErrors();
        $this->post('/student/queue', ['service' => 'nonsense'])->assertSessionHasErrors('service');

        // Cancel frees the slot.
        $this->post("/student/queue/{$entry->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $entry->fresh()->status);
        $this->post('/student/queue', ['service' => 'enrollment'])->assertSessionHasNoErrors();
    }

    public function test_queue_entries_belong_to_the_logged_in_student_only(): void
    {
        $juansEntry = $this->juan()->queueEntries()->first();

        // A student_no in the form is ignored for students.
        $this->actingAs($this->ana()->user)
            ->post('/student/queue', ['service' => 'grades', 'student_no' => '2024-00001']);
        $this->assertSame(1, QueueEntry::where('student_id', $this->ana()->id)->count());
        $this->assertSame(1, QueueEntry::where('student_id', $this->juan()->id)->count());

        $this->post("/student/queue/{$juansEntry->id}/cancel")->assertForbidden();
        $this->assertSame('waiting', $juansEntry->fresh()->status);
    }

    public function test_an_old_unserved_entry_does_not_block_a_new_number(): void
    {
        $this->actingAs($this->ana()->user)->post('/student/queue', ['service' => 'clearance']);
        $old = QueueEntry::where('student_id', $this->ana()->id)->first();
        $old->forceFill(['queue_date' => today()->subDay()])->saveQuietly();

        $this->post('/student/queue', ['service' => 'clearance'])->assertSessionHasNoErrors();
        $this->assertSame('skipped', $old->fresh()->status);
    }

    // ---- History + access control -------------------------------------------------------------

    public function test_history_page_lists_only_the_students_own_records(): void
    {
        $this->actingAs($this->juan()->user)->get('/student/history')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('StudentHistory')
                ->has('enrollments', 1)->has('documents', 2)->has('queue', 1)->has('clearances', 1));

        $this->actingAs($this->ana()->user)->get('/student/history')
            ->assertInertia(fn (Assert $p) => $p
                ->has('enrollments', 0)->has('documents', 0)->has('queue', 0)->has('clearances', 0));
    }

    public function test_other_roles_cannot_use_student_endpoints(): void
    {
        $cashier = User::where('email', 'cashier@school.test')->first();

        $this->actingAs($cashier)->get('/student')->assertForbidden();
        $this->post('/student/queue', ['service' => 'enrollment'])->assertForbidden();
        $this->post('/student/enrollments', $this->enrollPayload())->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/student')->assertRedirect('/login');
        $this->get('/documents')->assertRedirect('/login');
    }

    public function test_admin_without_a_student_profile_sees_a_notice_not_an_error(): void
    {
        $admin = User::where('email', 'admin@school.test')->first();

        $this->actingAs($admin)->get('/student')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('student', null));
        // No student profile -> no queue number (refused in validation, nothing created).
        $this->post('/student/queue', ['service' => 'enrollment'])->assertSessionHasErrors('student_no');
        $this->assertSame(1, QueueEntry::count());
    }
}
