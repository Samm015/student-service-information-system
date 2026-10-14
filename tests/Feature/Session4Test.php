<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\QueueEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Session 4: expected release date (Registration only), the queue as the student sees it,
 * the Admin portal, account deactivation, and the matching role security.
 */
class Session4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $email): User { return User::where('email', $email)->firstOrFail(); }
    private function registrar(): User { return $this->user('registration@school.test'); }
    private function cashier(): User   { return $this->user('cashier@school.test'); }
    private function deptHead(): User  { return $this->user('department@school.test'); }
    private function admin(): User     { return $this->user('admin@school.test'); }
    private function ana(): Student     { return Student::findByNumber('2024-00002'); }
    private function leo(): Student     { return Student::findByNumber('2024-00003'); }
    private function leosCoe(): DocumentRequest { return DocumentRequest::where('student_id', $this->leo()->id)->firstOrFail(); }

    // ======================= Document release date =======================

    public function test_a_student_cannot_choose_the_release_date(): void
    {
        $tor = DocumentType::where('code', 'TOR')->firstOrFail();
        $date = now()->addDays(2)->toDateString();

        $this->actingAs($this->ana()->user)->post('/documents', [
            'document_type_id' => $tor->id, 'copies' => 1, 'purpose' => 'Work',
            'expected_release_date' => $date, 'pickup_date' => $date, // both are ignored
        ])->assertSessionHasNoErrors();

        $request = DocumentRequest::where('student_id', $this->ana()->id)->firstOrFail();
        $this->assertNull($request->expected_release_date);

        // ... and cannot change it afterwards.
        $this->patch("/documents/{$request->id}/release-date", ['expected_release_date' => $date])->assertForbidden();
        $this->assertNull($request->fresh()->expected_release_date);
    }

    public function test_only_registration_can_set_the_release_date(): void
    {
        $coe  = $this->leosCoe();
        $date = now()->addDays(3)->toDateString();

        foreach ([$this->leo()->user, $this->cashier(), $this->deptHead()] as $user) {
            $this->actingAs($user)->patch("/documents/{$coe->id}/release-date", ['expected_release_date' => $date])->assertForbidden();
        }
        $this->assertNull($coe->fresh()->expected_release_date);
    }

    public function test_registration_sets_and_updates_the_release_date_and_the_student_sees_it(): void
    {
        $coe    = $this->leosCoe();
        $first  = now()->addDays(3);
        $second = now()->addDays(5);

        $this->actingAs($this->registrar())
            ->patch("/documents/{$coe->id}/release-date", ['expected_release_date' => $first->toDateString()])
            ->assertSessionHas('success');
        $this->assertSame($first->toDateString(), $coe->fresh()->expected_release_date->toDateString());

        $this->actingAs($this->leo()->user)->get('/documents')
            ->assertInertia(fn (Assert $p) => $p->where('requests.0.expected_release_date', $first->format('M d, Y')));

        // Update it: the history and the audit log record both changes.
        $this->actingAs($this->registrar())
            ->patch("/documents/{$coe->id}/release-date", ['expected_release_date' => $second->toDateString()])
            ->assertSessionHas('success');
        $this->assertSame($second->toDateString(), $coe->fresh()->expected_release_date->toDateString());
        $this->assertSame(2, $coe->statusHistories()->where('remarks', 'like', 'Expected release date%')->count());
        $this->assertSame(2, AuditLog::where('action', 'document_request.release_date')->count());

        // The student's own dashboard shows it too.
        $this->actingAs($this->leo()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('documents.0.expected_release_date', $second->format('M d, Y')));
    }

    public function test_release_date_must_be_a_real_date_that_is_not_in_the_past(): void
    {
        $coe = $this->leosCoe();
        $this->actingAs($this->registrar());

        $this->patch("/documents/{$coe->id}/release-date", ['expected_release_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('expected_release_date');
        $this->patch("/documents/{$coe->id}/release-date", ['expected_release_date' => 'soon'])
            ->assertSessionHasErrors('expected_release_date');
        $this->patch("/documents/{$coe->id}/release-date", [])
            ->assertSessionHasErrors('expected_release_date');
        $this->assertNull($coe->fresh()->expected_release_date);
    }

    public function test_a_finished_request_no_longer_takes_a_release_date(): void
    {
        $coe = $this->leosCoe();
        $coe->forceFill(['status' => 'released'])->save();

        $this->actingAs($this->registrar())
            ->patch("/documents/{$coe->id}/release-date", ['expected_release_date' => now()->addDay()->toDateString()])
            ->assertSessionHas('error');
        $this->assertNull($coe->fresh()->expected_release_date);
    }

    public function test_the_student_payload_describes_payment_and_progress_from_the_database(): void
    {
        // Leo's COE has a fee: after Registration accepts it, the student sees "unpaid" and the payment step.
        $coe = $this->leosCoe();
        $this->actingAs($this->registrar())->patch("/documents/{$coe->id}/status", ['status' => 'processing'])->assertSessionHas('success');

        $this->actingAs($this->leo()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p
                ->where('documents.0.status', 'awaiting_payment')
                ->where('documents.0.payment.status', 'pending')
                ->where('documents.0.tracker.current', 'awaiting_payment')
                ->has('documents.0.tracker.steps', 5));
    }

    // ======================= Queue as the student sees it =======================

    public function test_student_b_sees_student_a_being_served_and_then_moves_up(): void
    {
        // 1-2. A (Ana) and B (Leo) join the same service.
        $this->actingAs($this->ana()->user)->post('/student/queue', ['service' => 'grades'])->assertSessionHasNoErrors();
        $this->actingAs($this->leo()->user)->post('/student/queue', ['service' => 'grades'])->assertSessionHasNoErrors();
        $a = QueueEntry::where('student_id', $this->ana()->id)->firstOrFail();
        $b = QueueEntry::where('student_id', $this->leo()->id)->firstOrFail();

        // B starts behind A.
        $this->actingAs($this->leo()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('queue.active.0.ahead', 1)->where('queue.active.0.now_serving', null));

        // 3. Staff calls A.
        $this->actingAs($this->registrar())->post('/staff/queue/call-next', ['counter' => 'Window 2', 'service' => 'grades'])->assertSessionHas('success');
        $this->assertSame('serving', $a->fresh()->status);

        // 4. B sees A as now serving, with the window.
        $this->actingAs($this->leo()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p
                ->where('queue.active.0.code', $b->queue_code)
                ->where('queue.active.0.now_serving', $a->queue_code)
                ->where('queue.active.0.now_serving_counter', 'Window 2')
                ->where('queue.active.0.ahead', 0)
                ->where('queue.active.0.status', 'waiting'));

        // 5. Staff completes A.
        $this->actingAs($this->registrar());
        $this->patch("/staff/queue/{$a->id}", ['action' => 'start'])->assertSessionHas('success');
        $this->patch("/staff/queue/{$a->id}", ['action' => 'complete'])->assertSessionHas('success');

        // 6. B is next: nobody is ahead and nobody is being served.
        $this->actingAs($this->leo()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('queue.active.0.ahead', 0)->where('queue.active.0.now_serving', null));

        // Only staff move the line: a student cannot call, skip or reorder.
        $this->patch("/staff/queue/{$b->id}", ['action' => 'skip'])->assertForbidden();
        $this->post('/staff/queue/call-next', ['counter' => 'Window 1'])->assertForbidden();
        $this->assertSame('waiting', $b->fresh()->status);
    }

    public function test_a_student_cannot_hold_two_active_numbers_for_one_service(): void
    {
        $this->actingAs($this->ana()->user);
        $this->post('/student/queue', ['service' => 'grades'])->assertSessionHasNoErrors();
        $this->post('/student/queue', ['service' => 'grades'])->assertSessionHasErrors();
        $this->assertSame(1, QueueEntry::where('student_id', $this->ana()->id)->where('service', 'grades')->count());
    }

    public function test_registration_completed_today_comes_from_real_work(): void
    {
        // The demo data already holds work finished "today" (a seeded approval), so measure first.
        $before = $this->completedToday();

        // Do one real piece of work: Ana takes a number and the registrar serves her to the end.
        $this->actingAs($this->ana()->user)->post('/student/queue', ['service' => 'grades'])->assertSessionHasNoErrors();
        $entry = QueueEntry::where('student_id', $this->ana()->id)->firstOrFail();

        $this->actingAs($this->registrar());
        $this->post('/staff/queue/call-next', ['counter' => 'Window 1', 'service' => 'grades'])->assertSessionHas('success');
        $this->patch("/staff/queue/{$entry->id}", ['action' => 'start'])->assertSessionHas('success');
        $this->patch("/staff/queue/{$entry->id}", ['action' => 'complete'])->assertSessionHas('success');

        // Exactly one more item is counted, and the list still loads.
        $this->assertSame($before + 1, $this->completedToday());
        $this->actingAs($this->registrar())->get('/registration')
            ->assertInertia(fn (Assert $p) => $p->has('documents.rows')->where('documents.total', fn ($t) => $t >= 1));
    }

    // ======================= Admin: users =======================

    public function test_admin_dashboard_numbers_are_counted_from_the_database(): void
    {
        $this->actingAs($this->admin())->get('/admin')
            ->assertInertia(fn (Assert $p) => $p
                ->component('Admin')
                ->where('stats.users_active', User::where('is_active', true)->count())
                ->where('stats.users_inactive', 0)
                ->where('stats.open_documents', DocumentRequest::whereIn('status', DocumentRequest::ACTIVE_STATUSES)->count())
                ->has('sections', 4));
    }

    public function test_admin_can_search_and_filter_users(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/users?q=cashier')
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Users')->has('rows', 1)->where('rows.0.email', 'cashier@school.test'));
        $this->get('/admin/users?role=student')
            ->assertInertia(fn (Assert $p) => $p->has('rows', User::where('role', 'student')->count()));
        $this->get('/admin/users?q=2024-00002')
            ->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.student_no', '2024-00002'));
    }

    public function test_admin_creates_a_staff_account_that_can_log_in(): void
    {
        $csh = Department::where('code', 'CSH')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'New Cashier', 'email' => 'new.cashier@school.test', 'role' => 'cashier',
            'password' => 'secret123', 'department_id' => $csh->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $new = $this->user('new.cashier@school.test');
        $this->assertTrue($new->is_active);
        $this->assertSame($csh->id, $new->department_id);
        $this->assertNotSame('secret123', $new->password); // stored hashed
        $this->assertTrue(AuditLog::where('action', 'user.created')->exists());
    }

    public function test_admin_creates_a_student_with_a_profile(): void
    {
        $ccs = Department::where('code', 'CCS')->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'New Student', 'email' => 'new.student@school.test', 'role' => 'student', 'password' => 'secret123',
            'department_id' => $ccs->id, 'student_no' => '2025-00001', 'program' => 'BSIT', 'year_level' => 1,
        ])->assertSessionHasNoErrors();

        $student = Student::findByNumber('2025-00001');
        $this->assertNotNull($student);
        $this->assertSame('New Student', $student->user->name);
    }

    public function test_user_validation(): void
    {
        $this->actingAs($this->admin());
        $csh = Department::where('code', 'CSH')->firstOrFail();

        // duplicate email, short password, unknown role
        $this->post('/admin/users', ['name' => 'X', 'email' => 'cashier@school.test', 'role' => 'cashier', 'password' => 'secret123', 'department_id' => $csh->id])
            ->assertSessionHasErrors('email');
        $this->post('/admin/users', ['name' => 'X', 'email' => 'x@school.test', 'role' => 'cashier', 'password' => 'short', 'department_id' => $csh->id])
            ->assertSessionHasErrors('password');
        $this->post('/admin/users', ['name' => 'X', 'email' => 'x@school.test', 'role' => 'wizard', 'password' => 'secret123'])
            ->assertSessionHasErrors('role');
        // staff need an office; students need a profile
        $this->post('/admin/users', ['name' => 'X', 'email' => 'x@school.test', 'role' => 'cashier', 'password' => 'secret123'])
            ->assertSessionHasErrors('department_id');
        $this->post('/admin/users', ['name' => 'X', 'email' => 'x@school.test', 'role' => 'student', 'password' => 'secret123', 'department_id' => $csh->id])
            ->assertSessionHasErrors(['student_no', 'program', 'year_level']);
        $this->assertNull(User::where('email', 'x@school.test')->first());
    }

    public function test_admin_edits_a_user_and_keeps_the_password_when_it_is_left_empty(): void
    {
        $cashier = $this->cashier();
        $hash    = $cashier->password;

        $this->actingAs($this->admin())->patch("/admin/users/{$cashier->id}", [
            'name' => 'Carlo C. Cashier', 'email' => $cashier->email, 'role' => 'cashier',
            'password' => '', 'department_id' => $cashier->department_id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Carlo C. Cashier', $cashier->fresh()->name);
        $this->assertSame($hash, $cashier->fresh()->password);
    }

    public function test_a_student_keeps_the_student_role_and_an_admin_keeps_their_own(): void
    {
        $juan  = Student::findByNumber('2024-00001')->user;
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->patch("/admin/users/{$juan->id}", ['name' => $juan->name, 'email' => $juan->email, 'role' => 'cashier', 'department_id' => 1])
            ->assertSessionHasErrors('role');
        $this->patch("/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'cashier', 'department_id' => 1])
            ->assertSessionHasErrors('role');
        $this->assertSame('student', $juan->fresh()->role);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_deactivated_accounts_cannot_log_in_and_lose_their_session(): void
    {
        $cashier = $this->cashier();
        $this->actingAs($this->admin())->patch("/admin/users/{$cashier->id}/status", ['is_active' => false])->assertSessionHas('success');
        $this->assertFalse($cashier->fresh()->is_active);
        $this->assertTrue(AuditLog::where('action', 'user.deactivated')->exists());

        // Already logged in: the next request ends the session.
        $this->actingAs($cashier->fresh())->get('/cashier')->assertRedirect('/login');
    }

    public function test_a_deactivated_account_is_refused_at_the_login_form(): void
    {
        $this->cashier()->update(['is_active' => false]);

        $this->post('/login', ['email' => 'cashier@school.test', 'password' => 'password'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // Switched back on, the same credentials work.
        $this->cashier()->update(['is_active' => true]);
        $this->post('/login', ['email' => 'cashier@school.test', 'password' => 'password'])->assertRedirect('/cashier');
        $this->assertAuthenticated();
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/status", ['is_active' => false])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    // ======================= Admin: departments, subjects, document types =======================

    public function test_admin_adds_and_edits_a_subject(): void
    {
        $ccs = Department::where('code', 'CCS')->firstOrFail();
        $this->actingAs($this->admin());

        $this->post('/admin/subjects', ['code' => ' itp900 ', 'title' => 'Capstone Seminar', 'units' => 3, 'department_id' => $ccs->id])
            ->assertSessionHasNoErrors();
        $subject = Subject::where('code', 'ITP900')->firstOrFail(); // trimmed + upper-cased
        $this->assertTrue($subject->is_active);

        $this->post('/admin/subjects', ['code' => 'ITP900', 'title' => 'Again', 'units' => 3, 'department_id' => $ccs->id])
            ->assertSessionHasErrors('code');
        $this->post('/admin/subjects', ['code' => 'ITP901', 'title' => 'Too many', 'units' => 12, 'department_id' => $ccs->id])
            ->assertSessionHasErrors('units');

        $this->patch("/admin/subjects/{$subject->id}", ['code' => 'ITP900', 'title' => 'Capstone Seminar I', 'units' => 4, 'department_id' => $ccs->id, 'is_active' => false])
            ->assertSessionHasNoErrors();
        $subject->refresh();
        $this->assertSame('Capstone Seminar I', $subject->title);
        $this->assertFalse($subject->is_active);

        // A deactivated subject no longer shows up for students to enroll in.
        $this->actingAs($this->ana()->user)->get('/student')
            ->assertInertia(fn (Assert $p) => $p->where('enrollment.subjects', fn ($list) => collect($list)->doesntContain('code', 'ITP900')));
    }

    public function test_admin_adds_edits_and_deactivates_a_document_type(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/document-types', ['code' => 'GMC2', 'name' => 'Good Moral (2nd)', 'fee' => '75.50', 'processing_days' => 2])
            ->assertSessionHasNoErrors();
        $type = DocumentType::where('code', 'GMC2')->firstOrFail();
        $this->assertSame('75.50', (string) $type->fee);

        $this->post('/admin/document-types', ['code' => 'BAD', 'name' => 'Bad', 'fee' => '-5', 'processing_days' => 2])->assertSessionHasErrors('fee');
        $this->post('/admin/document-types', ['code' => 'BAD', 'name' => 'Bad', 'fee' => '10.123', 'processing_days' => 2])->assertSessionHasErrors('fee');
        $this->post('/admin/document-types', ['code' => 'BAD', 'name' => 'Bad', 'fee' => '10', 'processing_days' => 0])->assertSessionHasErrors('processing_days');

        $this->patch("/admin/document-types/{$type->id}", ['code' => 'GMC2', 'name' => 'Good Moral (2nd)', 'fee' => '80', 'processing_days' => 2, 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($type->fresh()->is_active);

        // Students cannot request an inactive type.
        $this->actingAs($this->ana()->user)->post('/documents', ['document_type_id' => $type->id, 'copies' => 1, 'purpose' => 'Work'])
            ->assertSessionHasErrors('document_type_id');
    }

    public function test_admin_adds_and_edits_a_department(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/departments', ['code' => 'cba', 'name' => 'College of Business', 'type' => 'academic'])->assertSessionHasNoErrors();
        $dept = Department::where('code', 'CBA')->firstOrFail();

        $this->post('/admin/departments', ['code' => 'CBA', 'name' => 'Dup', 'type' => 'academic'])->assertSessionHasErrors('code');
        $this->post('/admin/departments', ['code' => 'XYZ', 'name' => 'Odd', 'type' => 'planet'])->assertSessionHasErrors('type');

        $this->patch("/admin/departments/{$dept->id}", ['code' => 'CBA', 'name' => 'College of Business Admin', 'type' => 'academic'])->assertSessionHasNoErrors();
        $this->assertSame('College of Business Admin', $dept->fresh()->name);

        $this->get('/admin/departments?type=office')
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Catalog')->where('rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['type'] === 'office')));
    }

    public function test_unknown_admin_sections_are_404(): void
    {
        $this->actingAs($this->admin())->get('/admin/payroll')->assertNotFound();
    }

    // ======================= Security =======================

    public function test_nobody_but_an_admin_reaches_the_admin_endpoints(): void
    {
        $juan = Student::findByNumber('2024-00001')->user;
        $csh  = Department::where('code', 'CSH')->firstOrFail();
        $subject = Subject::firstOrFail();

        $endpoints = [
            ['get',   '/admin', []],
            ['get',   '/admin/users', []],
            ['post',  '/admin/users', ['name' => 'Evil', 'email' => 'evil@school.test', 'role' => 'admin', 'password' => 'secret123']],
            ['patch', "/admin/users/{$juan->id}", ['name' => 'Evil', 'email' => 'evil@school.test', 'role' => 'student']],
            ['patch', "/admin/users/{$juan->id}/status", ['is_active' => false]],
            ['get',   '/admin/departments', []],
            ['post',  '/admin/departments', ['code' => 'EVL', 'name' => 'Evil', 'type' => 'office']],
            ['get',   '/admin/subjects', []],
            ['patch', "/admin/subjects/{$subject->id}", ['code' => 'EVIL', 'title' => 'Evil', 'units' => 3, 'department_id' => $csh->id]],
            ['get',   '/admin/document-types', []],
            ['post',  '/admin/document-types', ['code' => 'EVL', 'name' => 'Evil', 'fee' => '0', 'processing_days' => 1]],
        ];

        foreach ([$juan, $this->registrar(), $this->cashier(), $this->deptHead()] as $user) {
            foreach ($endpoints as [$method, $url, $payload]) {
                $this->actingAs($user)->{$method}($url, $payload)->assertForbidden("{$user->role} must not {$method} {$url}");
            }
        }

        $this->assertTrue($juan->fresh()->is_active);
        $this->assertNull(User::where('email', 'evil@school.test')->first());
        $this->assertNull(Department::where('code', 'EVL')->first());
    }

    public function test_guests_are_sent_to_login_for_admin_pages(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->post('/admin/users', [])->assertRedirect('/login');
    }

    public function test_a_student_cannot_read_another_students_document_request(): void
    {
        $this->actingAs($this->ana()->user)->get('/documents/' . $this->leosCoe()->id)->assertForbidden();
    }
    /** Reads the "completed today" number the registrar's dashboard shows. */
    private function completedToday(): int
    {
        $value = null;
        $this->actingAs($this->registrar())->get('/registration')
            ->assertInertia(function (Assert $page) use (&$value) {
                $value = $page->toArray()['props']['stats']['completed_today'];
            });

        return (int) $value;
    }
}
