<?php

namespace Database\Seeders;

use App\Models\Clearance;
use App\Models\ClearanceItem;
use App\Models\Department;
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
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const YEAR = '2025-2026';
    private const TERM = '1st Semester';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->call(ReferenceDataSeeder::class);
            return; // no demo accounts on the live site
        }
        $this->call(ReferenceDataSeeder::class);

        $dept = Department::pluck('id', 'code');

        // ---- Demo logins (password for all: "password") ------------------------
        // Same emails as before, so existing logins keep working.
        // Staff are attached to the department/office they work for.
        $staff = [
            ['Maria Registrar', 'registration@school.test', User::ROLE_REGISTRATION, 'REG'],
            ['Carlo Cashier',   'cashier@school.test',      User::ROLE_CASHIER,      'CSH'],
            ['Dr. Dept Head',   'department@school.test',   User::ROLE_DEPARTMENT,   'CCS'],
            ['System Admin',    'admin@school.test',        User::ROLE_ADMIN,        null],
        ];
        foreach ($staff as [$name, $email, $role, $deptCode]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name, 'role' => $role, 'password' => 'password',
                'department_id' => $deptCode ? $dept[$deptCode] : null,
            ]);
        }

        // ---- Students (Juan keeps his original login) ----------------------------
        $students = [
            ['Juan Dela Cruz', 'student@school.test',      '2024-00001', 'BSIT', 2],
            ['Ana Reyes',      'ana.reyes@school.test',    '2024-00002', 'BSIT', 2],
            ['Leo Santos',     'leo.santos@school.test',   '2024-00003', 'BSCS', 2],
        ];
        foreach ($students as [$name, $email, $no, $program, $year]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name, 'role' => User::ROLE_STUDENT, 'password' => 'password',
            ]);
            Student::updateOrCreate(['student_no' => $no], [
                'user_id' => $user->id, 'department_id' => $dept['CCS'],
                'program' => $program, 'year_level' => $year,
            ]);
        }

        // Sample transactional data is only created on a fresh database.
        if (Enrollment::exists()) {
            return;
        }

        $this->seedSampleData();
    }

    private function seedSampleData(): void
    {
        $juan = Student::findByNumber('2024-00001');
        $ana  = Student::findByNumber('2024-00002');
        $leo  = Student::findByNumber('2024-00003');

        $registrar = User::where('email', 'registration@school.test')->first();
        $cashier   = User::where('email', 'cashier@school.test')->first();
        $deptHead  = User::where('email', 'department@school.test')->first();

        $subjects = Subject::pluck('id', 'code');

        // ---- Grades (these three match the Student page: 1.50 / 1.75 / 2.00) ---------
        $grades = [
            [$juan, 'ITEW3', 1.50], [$juan, 'ITP104', 1.75], [$juan, 'NET101', 2.00],
            [$ana,  'ITEW3', 1.25], [$ana,  'ITP104', 1.50],
            [$leo,  'ITEW3', 2.25], [$leo,  'NET101', 2.50],
        ];
        foreach ($grades as [$student, $code, $value]) {
            Grade::create([
                'student_id' => $student->id, 'subject_id' => $subjects[$code],
                'school_year' => self::YEAR, 'semester' => self::TERM,
                'grade' => $value, 'remarks' => $value <= 3.0 ? 'passed' : 'failed',
                'encoded_by' => $deptHead->id, 'encoded_at' => now(),
            ]);
        }

        // ---- Enrollments: Juan pending, Leo approved ------------------------------------
        // Enrollments and the clearance are for the CURRENT term (config/school.php);
        // the grades above are from the previous term.
        $next = AcademicTerm::current();

        $pending = Enrollment::create($next + [
            'student_id' => $juan->id, 'year_level' => 3, 'submitted_at' => now(),
        ]);
        $pending->subjects()->attach([$subjects['ITP101'], $subjects['GE101']]);
        $pending->statusHistories()->create(['from_status' => null, 'to_status' => 'pending', 'changed_by' => $juan->user_id]);

        $approved = Enrollment::create($next + [
            'student_id' => $leo->id, 'year_level' => 3, 'submitted_at' => now()->subDay(),
        ]);
        $approved->subjects()->attach([$subjects['MATH101'], $subjects['ITP101']]);
        $approved->changeStatus(Enrollment::STATUS_APPROVED, $registrar);
        $approved->update(['reviewed_by' => $registrar->id, 'reviewed_at' => now()]);

        // ---- Clearance: Juan has 1 of 3 signed (Cashier) ----
        $clearance = Clearance::openFor($juan, $next['school_year'], $next['semester']);
          $signedOffices = Department::whereIn('code', ['CSH'])->pluck('id')->all();
        foreach ($clearance->items as $item) {
            if (in_array($item->department_id, $signedOffices, true)) {
                $item->forceFill(['signed_by' => $cashier->id, 'signed_at' => now()])
                     ->changeStatus(ClearanceItem::STATUS_SIGNED, $cashier);
            }
        }
        $clearance->refreshStatus();

        // ---- Payments (match the Cashier page) ---------------------------------------------
        $paid = [
            [$juan, 'tuition',       12000],
            [$ana,  'document_fee',    150],
            [$leo,  'miscellaneous',  2300],
        ];
        foreach ($paid as [$student, $feeType, $amount]) {
            Payment::create(['student_id' => $student->id, 'fee_type' => $feeType, 'amount' => $amount])
                ->markPaid($cashier);
        }

        // ---- Document requests (match the Documents page) ------------------------------------
        $tor = DocumentType::where('code', 'TOR')->first();
        $gmc = DocumentType::where('code', 'GMC')->first();

        $processing = DocumentRequest::create([
            'student_id' => $juan->id, 'document_type_id' => $tor->id, 'copies' => 1,
            'purpose' => 'Employment', 'fee_total' => $tor->fee * 1,
        ]);
        Payment::create([
            'student_id' => $juan->id, 'document_request_id' => $processing->id,
            'fee_type' => 'document_fee', 'amount' => $processing->fee_total,
        ])->markPaid($cashier);
        $processing->changeStatus(DocumentRequest::STATUS_PROCESSING, $registrar);

        $ready = DocumentRequest::create([
            'student_id' => $juan->id, 'document_type_id' => $gmc->id, 'copies' => 2,
            'purpose' => 'Scholarship application', 'fee_total' => $gmc->fee * 2,
        ]);
        $ready->changeStatus(DocumentRequest::STATUS_READY, $registrar);

        // Leo has asked for a Certificate of Enrollment: Registration still has to accept it.
        $coe = DocumentType::where('code', 'COE')->first();
        DocumentRequest::create([
            'student_id' => $leo->id, 'document_type_id' => $coe->id, 'copies' => 1,
            'purpose' => 'Internship requirement', 'fee_total' => $coe->fee * 1,
        ])->statusHistories()->create(['from_status' => null, 'to_status' => 'pending', 'changed_by' => $leo->user_id]);

        // One unpaid request so the Cashier "pending payments" view has something to show.
        Payment::create([
            'student_id' => $ana->id, 'fee_type' => 'clearance_fee', 'amount' => 100,
        ]);

        // ---- Queue: Juan is waiting for the enrollment window -----------------------------------
        QueueEntry::issue($juan, 'enrollment');
    }
}
