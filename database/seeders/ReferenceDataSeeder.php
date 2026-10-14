<?php

namespace Database\Seeders;

use App\Models\ClearanceRequirement;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Lookup data the system needs to function (safe to keep in production):
 * departments/offices, subjects, document types, clearance requirements.
 * Uses updateOrCreate, so running it twice does not create duplicates.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // Departments (academic) and offices (service units that sign clearances)
        $departments = [
            ['CCS', 'College of Computing Studies', Department::TYPE_ACADEMIC],
            ['CAS', 'College of Arts and Sciences', Department::TYPE_ACADEMIC],
            ['LIB', 'Library',                      Department::TYPE_OFFICE],
            ['CSH', 'Cashier Office',               Department::TYPE_OFFICE],
            ['REG', 'Registrar Office',             Department::TYPE_OFFICE],
            ['GUI', 'Guidance Office',              Department::TYPE_OFFICE],
        ];
        foreach ($departments as [$code, $name, $type]) {
            Department::updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
        }
        $dept = Department::pluck('id', 'code');

        // Subjects (the first three match the UI mock-up)
        $subjects = [
            ['ITEW3',  'Mobile Programming 1',        3, 'CCS'],
            ['ITP104', 'Database Management Systems', 3, 'CCS'],
            ['NET101', 'Networking 1',                3, 'CCS'],
            ['ITP101', 'Introduction to Programming', 3, 'CCS'],
            ['GE101',  'Purposive Communication',     3, 'CAS'],
            ['MATH101', 'Discrete Mathematics',       3, 'CAS'],
        ];
        foreach ($subjects as [$code, $title, $units, $deptCode]) {
            Subject::updateOrCreate(['code' => $code], [
                'title' => $title, 'units' => $units, 'department_id' => $dept[$deptCode],
            ]);
        }

        // Document types (fee is per copy, in pesos; matches the Documents page)
        $documentTypes = [
            ['TOR',  'Transcript of Records',      150, 5],
            ['COE',  'Certificate of Enrollment',   50, 2],
            ['GMC',  'Good Moral Certificate',      50, 3],
            ['DIP',  'Diploma Copy',               100, 7],
        ];
        foreach ($documentTypes as [$code, $name, $fee, $days]) {
            DocumentType::updateOrCreate(['code' => $code], [
                'name' => $name, 'fee' => $fee, 'processing_days' => $days,
            ]);
        }

        // Clearance requirements (the five offices shown in the Student portal).
        // department = null means "the student's own academic department".
        $requirements = [
            [1, 'No outstanding balance',     'CSH'],
            [2, 'Thesis/Capstone submission', null],
            [3, 'Records verification',       'REG'],
        ];
        foreach ($requirements as [$order, $name, $deptCode]) {
            ClearanceRequirement::updateOrCreate(['name' => $name], [
                'sort_order'    => $order,
                'department_id' => $deptCode ? $dept[$deptCode] : null,
            ]);
        }
    }
}
